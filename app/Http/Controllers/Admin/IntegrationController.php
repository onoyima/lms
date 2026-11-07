<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\PlagiarismCheck;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\AnalyticsFeed;
use App\Services\Integrations\Connectors;
use App\Services\Integrations\PlagiarismConnector;
use App\Services\Lms\LmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The institution's connections to other systems: API keys for the student information system,
 * attendance terminals and analytics (LMS 221, 224), and the plagiarism service (LMS 222).
 */
class IntegrationController extends Controller
{
    public const SCOPES = [
        'sis.write' => 'Student information system: send students, courses and registrations',
        'results.read' => 'Read published results',
        'attendance.write' => 'Attendance terminals and apps: send attendance',
        'analytics.read' => 'Reporting and analytics: read course-level figures',
    ];

    public function __construct(private Connectors $connectors, private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/integrations', [
            'keys' => DB::table('api_keys')->where('tenant_id', tenant_id())->orderByDesc('id')->get(['id', 'name', 'prefix', 'scopes', 'allowed_ips', 'last_used_at', 'expires_at', 'revoked_at', 'created_at'])
                ->map(fn ($k) => array_merge((array) $k, ['scopes' => json_decode($k->scopes, true), 'allowed_ips' => json_decode($k->allowed_ips ?? 'null', true)])),
            'scopes' => self::SCOPES,
            'plagiarism' => $this->connectors->public('plagiarism'),
            'analytics' => $this->connectors->public('analytics'),
            'mail' => $this->connectors->public('mail'),
            'notify' => $this->connectors->public('notify'),
            'directory' => $this->connectors->public('directory'),
            'directoryRuns' => DB::table('sync_runs')->where('tenant_id', tenant_id())->where('kind', 'directory')->orderByDesc('id')->limit(5)->get(['id', 'response', 'errors', 'created_at'])
                ->map(fn ($r) => ['id' => $r->id, 'at' => $r->created_at, 'summary' => json_decode((string) $r->response, true), 'errors' => array_slice((array) json_decode((string) $r->errors, true), 0, 50)]),
            'directoryTest' => session('directory_test'),
            'callback' => url('/api/v1/plagiarism/callback/{reference}'),
            'apiBase' => url('/api/v1'),
            'newKey' => session('new_key'),
        ]);
    }

    public function createKey(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['in:'.implode(',', array_keys(self::SCOPES))],
            'ips' => ['nullable', 'string', 'max:500'], 'days' => ['required', 'integer', 'min:1', 'max:730']]);
        $ips = collect(preg_split('/[\s,]+/', (string) ($data['ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->values();
        foreach ($ips as $ip) {
            abort_unless(preg_match('#^[0-9a-f:.]+(/\d{1,3})?$#i', $ip), 422, "$ip is not an IP address or range.");
        }
        $prefix = Str::random(8);
        $secret = Str::random(40);
        DB::table('api_keys')->insert(['tenant_id' => tenant_id(), 'name' => $data['name'], 'prefix' => $prefix, 'key_hash' => hash('sha256', $secret),
            'scopes' => json_encode(array_values(array_unique($data['scopes']))), 'allowed_ips' => $ips->isEmpty() ? null : $ips->toJson(),
            'expires_at' => now()->addDays($data['days']), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('integration.key_created', null, ['prefix' => $prefix, 'scopes' => $data['scopes']]);

        // shown once, on the next page only
        return back()->with('new_key', "vk_{$prefix}_{$secret}")->with('success', 'Key created. Copy it now; it is not shown again.');
    }

    public function revokeKey(int $id): RedirectResponse
    {
        $n = DB::table('api_keys')->where('tenant_id', tenant_id())->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        abort_unless($n, 404);
        $this->audit->record('integration.key_revoked', null, ['id' => $id]);

        return back()->with('success', 'Key revoked. Systems using it stop working at once.');
    }

    public function saveConnector(Request $request, string $name): RedirectResponse
    {
        abort_unless(in_array($name, ['plagiarism', 'analytics', 'mail', 'notify'], true), 404);
        if ($name === 'mail') {
            $data = $request->validate(['enabled' => ['boolean'], 'host' => ['nullable', 'string', 'max:190'], 'port' => ['required', 'integer', 'in:25,465,587,2525'],
                'encryption' => ['required', 'in:tls,ssl,none'], 'username' => ['nullable', 'string', 'max:190'], 'secret' => ['nullable', 'string', 'max:300'],
                'from_address' => ['nullable', 'email', 'max:190'], 'from_name' => ['nullable', 'string', 'max:120']]);
            if (! empty($data['host'])) {
                abort_unless(Connectors::safeHost($data['host']), 422, 'That mail server address is not allowed.');
            }
            abort_if(($data['enabled'] ?? false) && (empty($data['host']) || empty($data['from_address'])), 422, 'Give the mail server and the sender address before switching it on.');
        } else {
            $data = $request->validate(['enabled' => ['boolean'], 'name' => ['nullable', 'string', 'max:60'], 'url' => ['nullable', 'url', 'max:500'], 'secret' => ['nullable', 'string', 'min:24', 'max:200'],
                'auto' => ['boolean'], 'include_personal' => ['boolean'], 'urgent_only' => ['boolean']]);
            if (! empty($data['url'])) {
                abort_unless(Connectors::safeUrl($data['url']), 422, 'Use an https address on the internet (not a private network address).');
            }
            abort_if(($data['enabled'] ?? false) && empty($data['url']), 422, 'Give the service address before switching it on.');
            $data['include_personal'] = false; // course-level figures only; personal data goes through results.read with its own approval
        }
        $this->connectors->save($name, $data);
        \App\Services\Integrations\InstitutionMail::apply(tenant()->fresh());
        $this->audit->record('integration.connector_saved', null, ['connector' => $name, 'enabled' => (bool) ($data['enabled'] ?? false), 'host' => $data['host'] ?? $data['url'] ?? null]);

        return back()->with('success', 'Saved.');
    }

    /**
     * The institution's records system through its directory bridge (people, courses, registrations), and
     * optionally sign-in through the same bridge: one switch sets up the "portal" single sign-on provider.
     */
    public function saveDirectory(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['boolean'], 'url' => ['nullable', 'url', 'max:300'], 'client_id' => ['nullable', 'string', 'max:120'],
            'secret' => ['nullable', 'string', 'min:16', 'max:200'], 'include_applicants' => ['boolean'], 'deactivate_missing' => ['boolean'],
            'follow_current_semester' => ['boolean'], 'sso' => ['boolean']]);
        $data['url'] = rtrim(trim((string) ($data['url'] ?? '')), '/');
        if ($data['url'] !== '' && ! \App\Services\Directory\DirectoryClient::safeUrl($data['url'])) {
            return back()->withErrors(['url' => 'Use the bridge\'s https address (http is only accepted on this computer during local development).']);
        }
        $current = $this->connectors->get('directory');
        $secret = ($data['secret'] ?? '') !== '' ? $data['secret'] : $current['secret'];
        if (($data['enabled'] ?? false) && ($data['url'] === '' || empty($data['client_id']) || $secret === '')) {
            return back()->withErrors(['url' => 'Give the bridge address, client ID and client secret before switching it on.']);
        }

        $providerId = $current['sso_provider_id'];
        if (($data['sso'] ?? false) && $data['url'] !== '' && ! empty($data['client_id']) && $secret !== '') {
            $provider = ($providerId ? \App\Models\SsoProvider::find($providerId) : null) ?? \App\Models\SsoProvider::where('slug', 'portal')->first();
            $new = ! $provider;
            $provider ??= new \App\Models\SsoProvider(['type' => 'oidc', 'slug' => 'portal', 'name' => 'University portal', 'auto_create' => false,
                'default_user_type' => 'student', 'default_role' => null, 'link_by_email' => true, 'trust_idp_mfa' => false]);
            abort_if($provider->type !== 'oidc', 422, 'A SAML provider already uses the short name "portal". Rename it under Single sign-on first.');
            $provider->fill([
                // kept for its role rules; people sign in on the normal sign-in form, so no button is shown
                'enabled' => false, 'type_attribute' => 'user_type',
                'config' => array_merge((array) ($provider->config ?? []), ['issuer' => $data['url'], 'client_id' => $data['client_id'], 'client_secret' => $secret,
                    'scopes' => 'openid email profile', 'claims' => ['email' => 'email', 'name' => 'name', 'identifier' => 'identifier']]),
            ]);
            if ($new) {
                // a starting point: the portal's functional roles to this institution's roles (edit under Single sign-on)
                $names = array_flip(\App\Support\Roles::names());
                $provider->role_rules = array_values(array_filter([
                    isset($names['hod']) ? ['attribute' => 'groups', 'value' => 'HOD', 'role' => 'hod'] : null,
                    isset($names['dean']) ? ['attribute' => 'groups', 'value' => 'Dean', 'role' => 'dean'] : null,
                    isset($names['exam_officer']) ? ['attribute' => 'groups', 'value' => 'Exam Officer', 'role' => 'exam_officer'] : null,
                ], fn ($r) => $r !== null && ! \App\Support\Roles::privileged($r['role'])));
            }
            $provider->save();
            $providerId = $provider->id;
        }

        $this->connectors->save('directory', array_merge($data, ['secret' => $data['secret'] ?? '', 'sso_provider_id' => $providerId]));
        $this->audit->record('integration.connector_saved', null, ['connector' => 'directory', 'enabled' => (bool) ($data['enabled'] ?? false), 'host' => $data['url'], 'sso' => (bool) ($data['sso'] ?? false)]);

        return back()->with('success', 'Saved.'.(($data['sso'] ?? false) && $providerId ? ' Portal staff, students and applicants can now sign in on the normal sign-in page with their portal ID and password. Map portal roles under Single sign-on > University portal.' : ''));
    }

    public function testDirectory(\App\Services\Directory\DirectorySync $sync): RedirectResponse
    {
        try {
            $result = $sync->test();
        } catch (\App\Services\Directory\DirectoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('directory_test', $result)->with('success', 'The bridge answered.');
    }

    public function syncDirectory(Request $request): RedirectResponse
    {
        abort_unless($this->connectors->get('directory')['enabled'], 422, 'Switch the directory connection on first.');
        $force = $request->boolean('force');
        \App\Jobs\SyncDirectory::dispatchAfterResponse((int) tenant_id(), $force);
        $this->audit->record('integration.directory_sync_requested', null, ['force' => $force]);

        return back()->with('success', 'Sync started. Refresh this page in a minute to see the result.');
    }

    /** Send a test e-mail through the institution's mail server to the person asking. */
    public function testMail(Request $request): RedirectResponse
    {
        \App\Services\Integrations\InstitutionMail::apply(tenant()->fresh());
        try {
            \Illuminate\Support\Facades\Mail::raw('This is a test from '.\App\Support\Platform\PlatformSettings::brand()['name'].'. If you can read it, e-mail from your institution is working.', fn ($m) => $m->to($request->user()->email)->subject(\App\Support\Platform\PlatformSettings::brand()['name'].' test e-mail'));
        } catch (\Throwable $e) {
            return back()->with('error', 'The mail server refused: '.\Illuminate\Support\Str::limit($e->getMessage(), 200));
        }

        return back()->with('success', 'Test e-mail sent to '.$request->user()->email.'. Check E-mail delivery for its status.');
    }

    public function pushAnalytics(AnalyticsFeed $feed): RedirectResponse
    {
        $r = $feed->push($this->connectors);

        return back()->with($r === 'sent' ? 'success' : 'error', 'Analytics feed: '.$r.'.');
    }

    /** A lecturer sends one submission to the plagiarism service. */
    public function checkSubmission(Request $request, AssignmentSubmission $submission, PlagiarismConnector $plagiarism, LmsAccess $access): RedirectResponse
    {
        abort_unless($access->teaches($request->user(), $submission->item->offering), 403);
        abort_if(PlagiarismCheck::where('assignment_submission_id', $submission->id)->whereIn('status', ['queued', 'sent'])->where('created_at', '>', now()->subHour())->exists(), 409, 'A check is already running for this submission.');
        $check = $plagiarism->send($submission, $request->user());

        return back()->with($check->status === 'failed' ? 'error' : 'success', $check->status === 'failed' ? 'The plagiarism service could not take the file: '.$check->error : 'Sent to '.$check->provider.'.');
    }
}
