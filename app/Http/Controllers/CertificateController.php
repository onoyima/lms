<?php

namespace App\Http\Controllers;

use App\Models\CertificateTemplate;
use App\Models\Exam;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\Certificates;
use App\Services\Reports\Exporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Certificate and badge designs, awards (automatic on results release, or by hand), downloads and revocation. */
class CertificateController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('certificates/index', [
            'templates' => CertificateTemplate::latest('id')->get()->map(fn ($t) => $t->toArray() + ['awards' => DB::table('certificate_awards')->where('certificate_template_id', $t->id)->whereNull('revoked_at')->count()]),
            'recent' => DB::table('certificate_awards as a')->join('users as u', 'u.id', '=', 'a.user_id')->join('certificate_templates as t', 't.id', '=', 'a.certificate_template_id')
                ->where('a.tenant_id', tenant_id())->latest('a.id')->limit(50)
                ->get(['a.id', 'a.code', 'a.subject_title', 'a.percent', 'a.grade', 'a.revoked_at', 'a.created_at', 'u.name', 'u.identifier', 't.name as template', 't.kind']),
            'icons' => CertificateTemplate::ICONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $t = CertificateTemplate::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->record('certificate.template_created', $t, []);

        return back()->with('success', ucfirst($t->kind).' design saved. Choose it on an exam (Rules) to award it.');
    }

    public function update(Request $request, CertificateTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request));

        return back()->with('success', 'Saved.');
    }

    /** Award for an exam now (to everyone whose published result reaches the pass percentage). */
    public function awardExam(Request $request, Exam $exam, Certificates $certificates): RedirectResponse
    {
        abort_unless($exam->certificate_template_id, 422, 'Choose a certificate or badge for this exam first (Rules).');
        $n = $certificates->awardForExam($exam, $request->user());
        $this->audit->record('certificate.awarded', $exam, ['count' => $n]);

        return back()->with('success', $n ? "$n awarded." : 'Nobody new to award: results must be published and reach the pass percentage.');
    }

    public function revoke(Request $request, int $award): RedirectResponse
    {
        $a = DB::table('certificate_awards')->where('tenant_id', tenant_id())->where('id', $award)->firstOrFail();
        DB::table('certificate_awards')->where('id', $a->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('issued_documents')->where('code', $a->code)->update(['revoked_at' => now()]);
        $this->audit->record('certificate.revoked', null, ['award' => $a->id, 'code' => $a->code, 'reason' => mb_substr((string) $request->input('reason'), 0, 200)]);

        return back()->with('success', 'Revoked. Checking its code now shows that it was revoked.');
    }

    /** The student's own certificates and badges. */
    public function mine(Request $request): Response
    {
        return Inertia::render('certificates/mine', [
            'awards' => DB::table('certificate_awards as a')->join('certificate_templates as t', 't.id', '=', 'a.certificate_template_id')
                ->where('a.user_id', $request->user()->id)->whereNull('a.revoked_at')->latest('a.id')
                ->get(['a.code', 'a.subject_title', 'a.percent', 'a.grade', 'a.created_at', 't.name', 't.kind', 't.colour', 't.badge_icon']),
        ]);
    }

    public function pdf(Request $request, string $code, Exporter $exporter)
    {
        $a = DB::table('certificate_awards')->where('code', strtoupper($code))->whereNull('revoked_at')->first();
        abort_unless($a && ($a->user_id === $request->user()->id || $request->user()->can('exams.manage')), 404);
        $t = CertificateTemplate::findOrFail($a->certificate_template_id);
        $user = \App\Models\User::findOrFail($a->user_id);

        return $exporter->pdf('certificate-'.$a->code, 'pdf.certificate', [
            'title' => $t->heading, 'template' => $t, 'holder' => $user, 'award' => $a,
            'body' => Certificates::fill($t->body, (array) $a, $user), 'verifyUrl' => url('/verify/'.$a->code), 'tenant' => tenant(),
        ], 'landscape');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'kind' => ['required', 'in:certificate,badge'],
            'heading' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'],
            'signatory_name' => ['nullable', 'string', 'max:120'], 'signatory_title' => ['nullable', 'string', 'max:120'],
            'colour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'badge_icon' => ['nullable', Rule::in(CertificateTemplate::ICONS)],
            'min_percent' => ['required', 'numeric', 'min:0', 'max:100'], 'auto_award' => ['boolean'],
        ]);
    }
}
