<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\ExamCentre;
use App\Models\ExamSession;
use App\Services\Exams\CentrePackages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Exam centres that run sittings without internet: the centres, their keys, and the packages both ways. */
class ExamCentreController extends Controller
{
    public function __construct(private CentrePackages $packages) {}

    public function index(): Response
    {
        return Inertia::render('admin/exam-centres', [
            'centres' => ExamCentre::orderBy('role')->orderBy('name')->get()->map(fn ($c) => $c->only(['id', 'name', 'code', 'role', 'notes']) + [
                'last_package_at' => $c->last_package_at?->toIso8601String(), 'last_results_at' => $c->last_results_at?->toIso8601String()]),
            'newKey' => session('centre_key'),
            'sittings' => ExamSession::with('exam:id,title')->whereNotNull('centre_ref')->latest('id')->limit(30)->get()
                ->map(fn ($s) => ['id' => $s->id, 'exam' => $s->exam?->title, 'name' => $s->name, 'starts_at' => $s->starts_at->toIso8601String()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('exam_centres')->where('tenant_id', tenant_id())],
            'role' => ['required', 'in:centre,main'],
            'secret' => ['nullable', 'required_if:role,main', 'string', 'min:32', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        // a centre gets a new key here; on a centre's server, the key from the main server is pasted in
        $key = $data['role'] === 'centre' ? Str::random(48) : $data['secret'];
        ExamCentre::create(['name' => $data['name'], 'code' => strtoupper($data['code']), 'role' => $data['role'], 'secret' => $key, 'notes' => $data['notes'] ?? null]);

        return back()->with('success', $data['role'] === 'centre' ? 'Centre added. Copy its key now: it is shown only once.' : 'Main server added.')
            ->with('centre_key', $data['role'] === 'centre' ? ['code' => strtoupper($data['code']), 'key' => $key] : null);
    }

    public function rotate(ExamCentre $centre): RedirectResponse
    {
        abort_unless($centre->role === 'centre', 422);
        $centre->update(['secret' => $key = Str::random(48)]);

        return back()->with('success', 'New key made. Give it to the centre; the old key no longer works.')->with('centre_key', ['code' => $centre->code, 'key' => $key]);
    }

    public function destroy(ExamCentre $centre): RedirectResponse
    {
        $centre->delete();

        return back()->with('success', 'Removed.');
    }

    /** Main server: the sitting packed for a centre. */
    public function package(Request $request, ExamSession $session)
    {
        $centre = ExamCentre::where('role', 'centre')->findOrFail($request->validate(['centre_id' => ['required', 'integer']])['centre_id']);
        $body = $this->packages->packSitting($session, $centre);
        $name = Str::slug($session->exam->title).'-sitting-'.$session->id.'-'.strtolower($centre->code).'.cbtpkg';

        return response($body, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Main server: the scripts the centre sent back. */
    public function results(Request $request, ExamSession $session): RedirectResponse
    {
        $data = $request->validate(['centre_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:204800']]);
        $centre = ExamCentre::where('role', 'centre')->findOrFail($data['centre_id']);
        $r = $this->packages->readResults((string) file_get_contents($data['file']->getRealPath()), $session, $centre);

        return back()->with('success', "{$r['added']} scripts added and marked.".($r['skipped'] ? " {$r['skipped']} were already here." : '').($r['missing'] ? ' Not found here: '.implode(', ', array_slice($r['missing'], 0, 10)).'.' : ''));
    }

    /** Centre's server: set up a sitting from the main server's package. */
    public function read(Request $request): RedirectResponse
    {
        $data = $request->validate(['centre_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:204800']]);
        $main = ExamCentre::where('role', 'main')->findOrFail($data['centre_id']);
        $session = $this->packages->readSitting((string) file_get_contents($data['file']->getRealPath()), $main, $request->user());

        return redirect("/sessions/{$session->id}")->with('success', 'Sitting ready on this server. Candidates sign in here with their usual password.');
    }

    /** Centre's server: the sitting's scripts packed for the main server. */
    public function pack(Request $request, ExamSession $session)
    {
        $main = ExamCentre::where('role', 'main')->findOrFail($request->validate(['centre_id' => ['required', 'integer']])['centre_id']);
        $body = $this->packages->packResults($session, $main);

        return response($body, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="results-sitting-'.$session->id.'.cbtpkg"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
