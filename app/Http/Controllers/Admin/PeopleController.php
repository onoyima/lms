<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People: staff and student accounts, roles (permanent or temporary), bulk CSV import,
 * deactivation and sign-in history (LMS 1-10, 177; Target 53-55, 199).
 * Admins cannot grant roles more powerful than their own, nor change their own roles.
 */
class PeopleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $users = User::query()->whereNotNull('tenant_id')->where('user_type', '!=', 'sandbox')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")->orWhere('identifier', 'like', "$q%")))
            ->when($request->query('type'), fn ($w, $t) => $w->where('user_type', $t))
            ->when($request->query('role'), fn ($w, $r) => $w->role($r))
            ->with('roles:id,name')->orderBy('name')->paginate(50)->withQueryString()
            ->through(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'identifier' => $u->identifier, 'type' => $u->user_type,
                'status' => $u->status, 'roles' => $u->roles->pluck('name'), 'two_factor' => (bool) $u->two_factor_confirmed_at,
                'placement' => DB::table('staff_profiles')->where('user_id', $u->id)->first(['faculty_id', 'department_id', 'title', 'designation'])]);

        return Inertia::render('admin/people', [
            'users' => $users,
            'roles' => \App\Support\Roles::names(),
            'roleLabels' => \App\Support\Roles::labels(),
            'grants' => DB::table('role_grants')->join('users', 'users.id', '=', 'role_grants.user_id')->where('role_grants.tenant_id', tenant_id())
                ->whereNull('role_grants.revoked_at')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('expires_at')->limit(100)->get(['role_grants.id', 'users.name', 'role_grants.role', 'role_grants.reason', 'role_grants.expires_at']),
            'logins' => DB::table('security_events')->leftJoin('users', 'users.id', '=', 'security_events.user_id')->where('security_events.tenant_id', tenant_id())
                ->whereIn('type', ['login', 'login_failed', 'login_locked'])->orderByDesc('security_events.id')->limit(50)
                ->get(['security_events.type', 'security_events.ip', 'security_events.created_at', 'users.name', 'security_events.data']),
            'filters' => $request->only(['q', 'type', 'role']),
            'faculties' => DB::table('faculties')->where('tenant_id', tenant_id())->orderBy('name')->get(['id', 'name']),
            'departments' => DB::table('departments')->where('tenant_id', tenant_id())->orderBy('name')->get(['id', 'name', 'faculty_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'], 'email' => ['required', 'email', 'max:190', Rule::unique('users')->where('tenant_id', tenant_id())],
            'identifier' => ['nullable', 'string', 'max:50', Rule::unique('users')->where('tenant_id', tenant_id())],
            'user_type' => ['required', 'in:staff,student'], 'roles' => ['array'], 'roles.*' => [Rule::in(\App\Support\Roles::names())],
        ]);
        $this->guardRoles($request->user(), $data['roles'] ?? []);
        abort_unless(\App\Support\Policy::emailAllowed($data['email']), 422, 'Use a university email address ('.implode(', ', (array) \App\Support\Policy::get('email_domains')).').');
        $user = User::forceCreate(['tenant_id' => tenant_id(), 'name' => $data['name'], 'email' => strtolower($data['email']), 'identifier' => $data['identifier'] ?? null,
            'user_type' => $data['user_type'], 'password' => Hash::make(Str::password(32)), 'email_verified_at' => now()]);
        $user->syncRoles($data['roles'] ?? [$data['user_type'] === 'student' ? 'student' : 'lecturer']);
        Password::sendResetLink(['email' => $user->email]); // they choose their own password
        $this->audit->record('people.created', $user, ['roles' => $data['roles'] ?? []]);

        return back()->with('success', "Account created. {$user->name} will receive a link to set a password.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id && $request->has('roles'), 403, 'You cannot change your own roles.');
        abort_if($user->is_platform_admin, 403);
        $data = $request->validate(['status' => ['sometimes', 'in:active,inactive,suspended'], 'roles' => ['sometimes', 'array'], 'roles.*' => [Rule::in(\App\Support\Roles::names())]]);
        if (isset($data['roles'])) {
            $this->guardRoles($request->user(), $data['roles']);
            $before = $user->getRoleNames()->all();
            $user->syncRoles($data['roles']);
            $this->audit->record('people.roles', $user, ['before' => $before, 'after' => $data['roles']]);
        }
        if (isset($data['status'])) {
            abort_if($user->id === $request->user()->id, 403, 'You cannot deactivate yourself.');
            $user->forceFill(['status' => $data['status']])->save();
            if ($data['status'] !== 'active') {
                DB::table('sessions')->where('user_id', $user->id)->delete(); // sign them out everywhere
            }
            $this->audit->record('people.status', $user, ['status' => $data['status']]);
        }

        return back()->with('success', 'Saved.');
    }

    /** Which department/faculty a staff member belongs to (decides what an HOD or Dean oversees). */
    public function placement(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->user_type === 'staff', 422);
        $data = $request->validate(['faculty_id' => ['nullable', 'integer', Rule::exists('faculties', 'id')->where('tenant_id', tenant_id())],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', tenant_id())],
            'title' => ['nullable', 'string', 'max:40'], 'designation' => ['nullable', 'string', 'max:80']]);
        if (! empty($data['department_id']) && empty($data['faculty_id'])) {
            $data['faculty_id'] = DB::table('departments')->where('id', $data['department_id'])->value('faculty_id');
        }
        DB::table('staff_profiles')->upsert([$data + ['tenant_id' => tenant_id(), 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id'], ['faculty_id', 'department_id', 'title', 'designation', 'updated_at']);
        $this->audit->record('people.placement', $user, $data);

        return back()->with('success', 'Placement saved.');
    }

    /** Temporary role (e.g. acting HOD for 3 weeks); removed automatically at expiry. */
    public function delegate(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(\App\Support\Roles::names())], 'expires_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'max:300']]);
        abort_if($user->id === $request->user()->id, 403, 'You cannot delegate to yourself.');
        $this->guardRoles($request->user(), [$data['role']]);
        $already = $user->hasRole($data['role']);
        DB::table('role_grants')->insert(['tenant_id' => tenant_id(), 'user_id' => $user->id, 'role' => $data['role'], 'reason' => $data['reason'],
            'expires_at' => $data['expires_at'], 'granted_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        if (! $already) {
            $user->assignRole($data['role']);
        }
        $this->audit->record('people.delegated', $user, $data);

        return back()->with('success', "{$user->name} has the {$data['role']} role until ".date('j M Y H:i', strtotime($data['expires_at'])).'.');
    }

    public function revoke(Request $request, int $grant): RedirectResponse
    {
        $g = DB::table('role_grants')->where('tenant_id', tenant_id())->where('id', $grant)->first();
        abort_unless($g, 404);
        self::endGrant($g);
        $this->audit->record('people.delegation_revoked', User::find($g->user_id), ['role' => $g->role]);

        return back();
    }

    public static function endGrant(object $g): void
    {
        DB::table('role_grants')->where('id', $g->id)->update(['revoked_at' => now()]);
        $stillGranted = DB::table('role_grants')->where('user_id', $g->user_id)->where('role', $g->role)->where('id', '!=', $g->id)->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
        if (! $stillGranted && ($u = User::withoutGlobalScopes()->find($g->user_id))) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($g->tenant_id);
            $u->removeRole($g->role);
        }
    }

    /** CSV: name,email,identifier,type,roles(;-separated). Max 5,000 rows; every row validated; errors reported. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $h = fopen($request->file('file')->getRealPath(), 'r');
        $head = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), fgetcsv($h) ?: []); // Excel may add a BOM
        $made = 0;
        $errors = [];
        $line = 1;
        $allowed = \App\Support\Roles::names();
        while (($row = fgetcsv($h)) !== false && $line < 5001) {
            $line++;
            $r = array_combine(array_slice($head, 0, count($row)), array_slice($row, 0, count($head))) ?: [];
            $email = strtolower(trim($r['email'] ?? ''));
            $roles = array_values(array_intersect(array_filter(array_map('trim', explode(';', $r['roles'] ?? ''))), $allowed));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || trim($r['name'] ?? '') === '') {
                $errors[] = "Line $line: name and a valid email are required.";

                continue;
            }
            if (! \App\Support\Policy::emailAllowed($email)) {
                $errors[] = "Line $line: $email is not a university address.";

                continue;
            }
            try {
                $this->guardRoles($request->user(), $roles);
                $type = ($r['type'] ?? '') === 'staff' ? 'staff' : 'student';
                $u = User::firstOrNew(['email' => $email]);
                $u->forceFill(['tenant_id' => tenant_id(), 'name' => trim($r['name']), 'identifier' => trim($r['identifier'] ?? '') ?: $u->identifier, 'user_type' => $type,
                    'password' => $u->password ?: Hash::make(Str::password(32)), 'email_verified_at' => $u->email_verified_at ?? now()])->save();
                $u->syncRoles($roles ?: [$type === 'student' ? 'student' : 'lecturer']);
                $made++;
            } catch (\Throwable $e) {
                $errors[] = "Line $line: ".($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : 'could not be saved (duplicate matric number?).');
            }
        }
        fclose($h);
        $this->audit->record('people.imported', null, ['rows' => $made, 'errors' => count($errors)]);

        return back()->with('success', "$made accounts imported.".($errors ? ' '.count($errors).' rows skipped: '.implode(' ', array_slice($errors, 0, 5)) : ''));
    }

    /** Nobody can hand out a role that holds a permission they do not have themselves. */
    private function guardRoles(User $actor, array $roles): void
    {
        foreach ($roles as $role) {
            abort_unless(\App\Support\Roles::canGrant($actor, $role), 403, "You cannot give the role $role: it includes permissions you do not have.");
        }
    }
}
