<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\ComputerCheck;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\ComputerHealth;
use App\Services\Exams\ExamComputers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Computer checks before an exam, and the page where computer problems are reported with their IP
 * address, from checks and from real exams.
 */
class ComputerCheckController extends Controller
{
    public function __construct(private ComputerHealth $health, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $hours = in_array((int) $request->query('hours'), [1, 6, 24, 72, 168], true) ? (int) $request->query('hours') : 24;

        return Inertia::render('exams/computer-checks', [
            'checks' => ComputerCheck::with('session.exam:id,title')->withCount([
                'reports', 'reports as failed_count' => fn ($q) => $q->where('status', 'fail'), 'reports as passed_count' => fn ($q) => $q->where('status', 'pass'),
            ])->latest()->limit(50)->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'open' => $c->isOpen(), 'closes_at' => $c->closes_at?->toIso8601String(),
                'exam' => $c->session?->exam?->title, 'computers' => $c->reports_count, 'failed' => $c->failed_count, 'passed' => $c->passed_count,
            ]),
            'sittings' => ExamSession::with('exam:id,title')->where('ends_at', '>', now())->orderBy('starts_at')->limit(100)->get()
                ->map(fn ($s) => ['id' => $s->id, 'label' => ($s->exam?->title ?? 'Exam').' · '.$s->starts_at->format('D j M, H:i'), 'needs' => $this->health->requirementsFor($s)]),
            'tests' => ComputerCheck::TESTS,
            'basic' => ComputerCheck::BASIC,
            'problems' => $this->health->examProblems($hours),
            'hours' => $hours,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'exam_session_id' => ['nullable', 'integer', 'exists:exam_sessions,id'],
            'requirements' => ['nullable', 'array'], 'requirements.*' => ['string', 'in:'.implode(',', array_keys(ComputerCheck::TESTS))],
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);
        $check = $this->health->create($data, $request->user()->id);
        $this->audit->record('computer_check.created', $check);

        return redirect()->route('computer-checks.show', $check)->with('success', 'Computer check ready. Open the link on every lab computer.');
    }

    public function show(Request $request, ComputerCheck $check): Response
    {
        $check->load('session.exam:id,title');

        return Inertia::render('exams/computer-check', [
            'check' => [
                'id' => $check->id, 'name' => $check->name, 'code' => $check->code, 'open' => $check->isOpen(),
                'closes_at' => $check->closes_at?->toIso8601String(), 'exam' => $check->session?->exam?->title,
                'tests' => array_map(fn ($k) => ['key' => $k, 'label' => ComputerCheck::TESTS[$k]], $check->tests()),
                // the same link on the server's campus addresses, for lab computers that reach it by IP
                'urls' => array_values(array_map(fn ($a) => $request->getScheme().'://'.$a['ip'].(in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort()).'/check/'.$check->code,
                    array_filter(ExamComputers::serverAddresses($request), fn ($a) => $a['private']))),
                'url' => url('/check/'.$check->code),
            ],
            'rows' => $this->health->checkRows($check),
            'qr' => $this->qr(url('/check/'.$check->code)),
        ]);
    }

    private function qr(string $url): string
    {
        $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(180, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd
        )))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    public function rows(ComputerCheck $check): JsonResponse
    {
        return response()->json(['rows' => $this->health->checkRows($check), 'open' => $check->isOpen()]);
    }

    public function close(Request $request, ComputerCheck $check): RedirectResponse
    {
        $check->update(['closes_at' => now()]);
        $this->audit->record('computer_check.closed', $check);

        return back()->with('success', 'Check closed. The results stay here.');
    }

    public function approve(Request $request, ComputerCheck $check): RedirectResponse
    {
        $n = $this->health->approvePassed($check, $request->user()->id);
        $this->audit->record('computer_check.approved', $check, ['computers' => $n]);

        return back()->with('success', "{$n} computer(s) that passed are now approved for exams.");
    }

    /* ---------- the page a lab computer opens (no sign-in needed) ---------- */

    private function find(string $code): ComputerCheck
    {
        return ComputerCheck::withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }

    public function run(string $code): Response
    {
        $check = $this->find($code);

        return app(TenantContext::class)->run(Tenant::find($check->tenant_id), fn () => Inertia::render('check/run', [
            'check' => [
                'name' => $check->name, 'code' => $check->code, 'open' => $check->isOpen(),
                'tests' => array_map(fn ($k) => ['key' => $k, 'label' => ComputerCheck::TESTS[$k]], $check->tests()),
            ],
            'server_time' => now()->toIso8601String(),
        ]));
    }

    /** Small fixed payload for the speed test (not cached). */
    public function payload(string $code)
    {
        $this->find($code);

        return response(str_repeat('0123456789abcdef', 16384), 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function report(Request $request, string $code): JsonResponse
    {
        $check = $this->find($code);
        abort_unless($check->isOpen(), 410, 'This check has closed.');
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'results' => ['required', 'array', 'max:20'],
            'results.*.key' => ['required', 'string', 'max:20'],
            'results.*.ok' => ['required', 'boolean'],
            'results.*.warn' => ['nullable', 'boolean'],
            'results.*.detail' => ['nullable', 'string', 'max:200'],
        ]);

        $row = app(TenantContext::class)->run(Tenant::find($check->tenant_id), fn () => $this->health->record($check, $request, $data['results'], $data['label'] ?? null));

        return response()->json(['status' => $row->status, 'ip' => $row->ip, 'server_time' => now()->toIso8601String()]);
    }
}
