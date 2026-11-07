<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\ExamComputer;
use App\Models\Tenant;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\ExamComputers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Exam network: the address students use to reach the server, and the computers approved to sit exams. */
class NetworkController extends Controller
{
    public function __construct(private ExamComputers $computers, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $settings = ExamComputers::settings($tenant);
        $current = ExamComputer::current()->with('lastUser:id,name,identifier', 'venue:id,name')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")->orderByDesc('last_seen_at')->limit(1000)->get();
        $address = $settings['preferred_address'] ?: ($request->getHost());
        $url = $request->getScheme().'://'.$address.($request->getPort() && ! in_array($request->getPort(), [80, 443], true) ? ':'.$request->getPort() : '');
        $taken = Tenant::query()->where('id', '!=', $tenant->id)->get(['id', 'domains'])->flatMap(fn ($t) => $t->domains ?? [])->map(fn ($d) => strtolower($d))->all();

        return Inertia::render('exams/network', [
            'server' => [
                'addresses' => collect(ExamComputers::serverAddresses($request))->map(fn ($a) => $a + [
                    'reachable' => \App\Http\Middleware\ResolveTenant::defaultTenant()?->id === $tenant->id || in_array(strtolower($a['ip']), array_map('strtolower', $tenant->domains ?? []), true),
                    'other_institution' => in_array($a['ip'], $taken, true),
                ])->values(),
                'browser_host' => $request->getHost(),
                'port' => $request->getPort(),
            ],
            'settings' => ['preferred_address' => $settings['preferred_address'], 'auto_clear' => (bool) $settings['auto_clear']],
            'studentUrl' => $url,
            'qr' => $this->qr($url),
            'computers' => $current->map(fn (ExamComputer $c) => $this->row($c)),
            'counts' => ['pending' => $current->where('status', 'pending')->count(), 'approved' => $current->where('status', 'approved')->count(), 'rejected' => $current->where('status', 'rejected')->count()],
            'history' => ExamComputer::query()->whereNotNull('cleared_at')
                ->selectRaw("cleared_at, MAX(clear_reason) reason, COUNT(*) total, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) approved, SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) rejected, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) pending, MAX(cleared_by) cleared_by")
                ->groupBy('cleared_at')->orderByDesc('cleared_at')->limit(30)->get()
                ->map(fn ($h) => ['cleared_at' => \Illuminate\Support\Carbon::parse($h->cleared_at)->toIso8601String(), 'reason' => $h->reason, 'total' => (int) $h->total,
                    'approved' => (int) $h->approved, 'rejected' => (int) $h->rejected, 'pending' => (int) $h->pending, 'by' => $h->cleared_by ? \App\Models\User::find($h->cleared_by)?->name : null]),
            'venues' => Venue::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The computers on one wiped list, for tracing (who was allowed, who was refused). */
    public function batch(Request $request)
    {
        $at = $request->validate(['at' => ['required', 'date']])['at'];

        return ExamComputer::query()->where('cleared_at', \Illuminate\Support\Carbon::parse($at))->with('lastUser:id,name,identifier', 'venue:id,name')
            ->orderBy('status')->orderBy('ip')->limit(2000)->get()->map(fn ($c) => $this->row($c));
    }

    public function saveAddress(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preferred_address' => ['nullable', 'string', 'max:190', function ($attr, $value, $fail) {
                if ($value !== null && ! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $value)) {
                    $fail('Enter an IP address such as 10.10.0.109, or a host name.');
                }
            }],
            'auto_clear' => ['required', 'boolean'],
        ]);
        $tenant = $this->tenant();
        $address = $data['preferred_address'] ? strtolower(trim($data['preferred_address'])) : null;
        if ($address) {
            $other = Tenant::query()->where('id', '!=', $tenant->id)->get(['id', 'domains'])
                ->first(fn ($t) => in_array($address, array_map('strtolower', $t->domains ?? []), true));
            abort_if($other, 422, 'That address is already used by another institution on this platform.');
        }
        $this->computers->setPreferredAddress($tenant, $address);
        $settings = (array) $tenant->fresh()->settings;
        $settings['exam_network']['auto_clear'] = (bool) $data['auto_clear'];
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->record('exam_network.address_saved', $tenant, ['address' => $address, 'auto_clear' => (bool) $data['auto_clear']]);

        return back()->with('success', $address ? "Students can now reach the exams on $address." : 'Saved.');
    }

    public function decide(Request $request, ExamComputer $computer): RedirectResponse
    {
        abort_if($computer->cleared_at, 409, 'This computer belongs to a list that was already cleared.');
        $data = $request->validate(['status' => ['nullable', 'in:approved,rejected,pending'], 'label' => ['nullable', 'string', 'max:80'], 'venue_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('venues', 'id')->where('tenant_id', tenant_id())]]);
        if ($request->has('label') || $request->has('venue_id')) {
            $computer->forceFill(array_intersect_key($data, array_flip(['label', 'venue_id'])))->save();
        }
        if (! empty($data['status'])) {
            $this->computers->decide($computer, $data['status'], $request->user()->id);
            $this->audit->record('exam_network.computer_'.$data['status'], $computer, ['ip' => $computer->ip]);
        }

        return back();
    }

    public function approveAll(Request $request): RedirectResponse
    {
        $n = ExamComputer::current()->where('status', 'pending')->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
        $this->audit->record('exam_network.approved_all', $this->tenant(), ['count' => $n]);

        return back()->with('success', "$n computers approved.");
    }

    public function reset(Request $request): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:150']])['reason'] ?? null;
        $n = $this->computers->clear($request->user()->id, $reason ?: 'Reset by '.$request->user()->name);
        $this->audit->record('exam_network.reset', $this->tenant(), ['count' => $n]);

        return back()->with('success', "Approvals cleared ($n computers). The list is kept under History.");
    }

    public function destroy(Request $request, ExamComputer $computer): RedirectResponse
    {
        abort_if($computer->cleared_at, 409);
        // never deleted: moved to history so the decision can still be traced
        $computer->forceFill(['cleared_at' => now(), 'cleared_by' => $request->user()->id, 'clear_reason' => 'Removed from the list'])->save();
        $this->audit->record('exam_network.computer_removed', $computer, ['ip' => $computer->ip]);

        return back();
    }

    private function row(ExamComputer $c): array
    {
        return [
            'id' => $c->id, 'ip' => $c->ip, 'label' => $c->label, 'status' => $c->status, 'venue' => $c->venue?->name, 'venue_id' => $c->venue_id,
            'student' => $c->lastUser ? ['name' => $c->lastUser->name, 'identifier' => $c->lastUser->identifier] : null,
            'requests' => $c->requests, 'first_seen_at' => $c->first_seen_at?->toIso8601String(), 'last_seen_at' => $c->last_seen_at?->toIso8601String(),
            'decided_at' => $c->decided_at?->toIso8601String(), 'decided_by' => $c->decided_by ? \App\Models\User::find($c->decided_by)?->name : null,
            'browser' => $c->user_agent ? $this->browser($c->user_agent) : null,
        ];
    }

    private function browser(string $ua): string
    {
        $os = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Mac OS') ? 'macOS' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'Linux') ? 'Linux' : '')));
        $b = match (true) {
            str_contains($ua, 'SEB') => 'Safe Exam Browser', str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Browser',
        };

        return trim("$b $os");
    }

    private function qr(string $url): string
    {
        $svg = (new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(180, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd
        )))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    private function tenant(): Tenant
    {
        return app(\App\Support\Tenancy\TenantContext::class)->get() ?? abort(404);
    }
}
