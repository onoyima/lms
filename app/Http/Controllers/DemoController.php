<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public demo: visitors pick a role and are signed in to the demo institution as that person.
 * No passwords are shown or needed, the demo shares no data with real institutions, and its data is
 * replaced every night.
 */
class DemoController extends Controller
{
    /** The roles a visitor can try, in the order shown. */
    public const ROLES = [
        'admin' => ['Institution administrator', 'Set up the institution, people, roles, policies and integrations.'],
        'examofficer' => ['Exams officer', 'Create exams, upload questions, add students, run sittings and publish results.'],
        'lecturer' => ['Lecturer', 'Write questions, run courses, mark essays and practicals.'],
        'setter' => ['Question setter', 'Build question banks and papers.'],
        'moderator' => ['Moderator', 'Review papers and question banks before they go live.'],
        'hod' => ['Head of department', 'Approve course results.'],
        'dean' => ['Dean', 'Approve results across the faculty.'],
        'chief' => ['Chief invigilator', 'Watch every batch on exam day, pause sittings, assign supervisors.'],
        'proctor' => ['Supervisor (proctor)', 'Watch your batches live and deal with alerts.'],
        'invigilator' => ['Invigilator', 'Check candidates in and help them in the hall.'],
        'ict' => ['ICT officer', 'Exam-hall network, computer checks and system health.'],
        'registry' => ['Registry', 'Students, programmes and academic records.'],
        'student' => ['Student', 'Take an exam, report a question, see results and courses.'],
    ];

    private function tenant(): ?Tenant
    {
        if (! PlatformSettings::all()['demo_enabled']) {
            return null;
        }
        $t = Tenant::where('slug', 'demo')->first();

        return $t?->isDemo() ? $t : null;
    }

    public function show(): Response
    {
        return Inertia::render('demo', [
            'available' => (bool) $this->tenant(),
            'roles' => collect(self::ROLES)->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'body' => $r[1]])->values(),
        ]);
    }

    public function enter(Request $request, string $role): RedirectResponse
    {
        abort_unless(array_key_exists($role, self::ROLES), 404);
        $tenant = $this->tenant();
        abort_unless($tenant, 404, 'The demo is not available right now.');
        $user = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', $role.'@demo.invalid')->where('status', 'active')->first();
        abort_unless($user, 404, 'The demo is being refreshed. Try again in a few minutes.');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);
        $request->session()->put('two_factor_passed', true);
        \App\Http\Middleware\SingleStudentSession::claim($user, $request);

        return redirect('/dashboard');
    }
}
