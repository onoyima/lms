<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Each institution decides its own roles: names, wording and exactly what each one may do.
 * Guard rails: nobody can give a role a permission they do not hold themselves, nobody edits a role they
 * hold (no self-promotion), the institution administrator role always keeps everything, and a role with
 * members cannot be deleted. Every change is audit-logged with before and after.
 */
class RoleController extends Controller
{
    private const LOCKED = 'institution_admin';

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key', 'tenant_id'), tenant_id())
            ->selectRaw('role_id, COUNT(*) as n')->groupBy('role_id')->pluck('n', 'role_id');

        return Inertia::render('admin/roles', [
            'roles' => Roles::all()->sortBy(fn (Role $r) => [$r->name !== self::LOCKED, ! $r->is_system, $r->label ?: $r->name])->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name, 'label' => $r->label ?: ucfirst(str_replace('_', ' ', $r->name)),
                'description' => $r->description, 'is_system' => (bool) $r->is_system,
                'permissions' => $r->permissions->pluck('name')->values(),
                'members' => (int) ($counts[$r->id] ?? 0),
                'editable' => $this->editable($actor, $r),
                'resettable' => (bool) $r->is_system && array_key_exists($r->name, config('roles.roles')) && $r->name !== self::LOCKED,
            ])->values(),
            'permissions' => collect(config('roles.permissions'))->map(fn ($p) => [
                'name' => $p, 'label' => config('roles.labels')[$p][0] ?? $p, 'description' => config('roles.labels')[$p][1] ?? null,
                'privileged' => in_array($p, config('roles.privileged'), true), 'grantable' => $actor->can($p),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $name = Str::limit(Str::slug($data['label'], '_'), 60, '');
        abort_if($name === '' || Role::where('tenant_id', tenant_id())->where('name', $name)->exists(), 422, 'A role with that name already exists.');
        $this->guard($request, $data['permissions']);

        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => tenant_id()]);
        $role->forceFill(['label' => $data['label'], 'description' => $data['description'], 'is_system' => false])->save();
        $role->syncPermissions($data['permissions']);
        $this->flush();
        $this->audit->record('role.created', $role, ['name' => $name, 'permissions' => $data['permissions']]);

        return back()->with('success', "Role \"{$data['label']}\" created. Assign it to anyone under People.");
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        $r = $this->find($role);
        abort_unless($this->editable($request->user(), $r), 403, 'You cannot change this role.');
        $data = $this->validated($request);
        $this->guard($request, $data['permissions']);

        $before = ['label' => $r->label, 'description' => $r->description, 'permissions' => $r->permissions->pluck('name')->all()];
        $r->forceFill(['label' => $data['label'], 'description' => $data['description']])->save();
        $r->syncPermissions($data['permissions']);
        $this->flush();
        $this->signOutWhoLostPower($r, $before['permissions']);
        $this->audit->record('role.updated', $r, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Role saved. It applies to everyone holding it straight away.');
    }

    public function destroy(Request $request, int $role): RedirectResponse
    {
        $r = $this->find($role);
        abort_unless($this->editable($request->user(), $r), 403, 'You cannot delete this role.');
        abort_if($r->name === self::LOCKED, 422, 'The institution administrator role cannot be deleted.');
        $members = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $r->id)->count();
        abort_if($members > 0, 422, "Remove this role from its {$members} member(s) first.");
        $this->audit->record('role.deleted', $r, ['name' => $r->name, 'permissions' => $r->permissions->pluck('name')->all()]);
        $r->delete();
        $this->flush();

        return back()->with('success', 'Role deleted.');
    }

    /** Members of a role that just lost a privileged permission are signed out everywhere (if no other role still gives it). */
    private function signOutWhoLostPower(Role $r, array $before): void
    {
        $removed = array_diff(array_intersect($before, config('roles.privileged')), $r->fresh()->permissions->pluck('name')->all());
        if (! $removed) {
            return;
        }
        $ids = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $r->id)->pluck('model_id');
        foreach (\App\Models\User::whereIn('id', $ids)->get() as $u) {
            $still = \App\Services\Security\SessionTerminator::privileged($u);
            if (array_diff($removed, $still)) {
                app(\App\Services\Security\SessionTerminator::class)->endFor($u, 'role '.$r->name.' lost '.implode(', ', $removed));
            }
        }
    }

    /** Put a starting role back to how the platform shipped it. */
    public function reset(Request $request, int $role): RedirectResponse
    {
        $r = $this->find($role);
        abort_unless($this->editable($request->user(), $r) && array_key_exists($r->name, config('roles.roles')), 403);
        $defaults = config("roles.roles.{$r->name}");
        $this->guard($request, $defaults);
        $before = $r->permissions->pluck('name')->all();
        $r->forceFill(['label' => config("roles.role_labels.{$r->name}.0"), 'description' => config("roles.role_labels.{$r->name}.1")])->save();
        $r->syncPermissions($defaults);
        $this->flush();
        $this->signOutWhoLostPower($r, $before);
        $this->audit->record('role.reset', $r, ['before' => $before, 'after' => $defaults]);

        return back()->with('success', 'Role reset to its default permissions.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['array', 'max:100'], 'permissions.*' => ['string', Rule::in(config('roles.permissions'))],
        ]);
        $data['permissions'] = array_values(array_unique($data['permissions'] ?? []));
        $data['description'] ??= null;

        return $data;
    }

    private function guard(Request $request, array $permissions): void
    {
        $missing = array_filter($permissions, fn ($p) => ! $request->user()->can($p));
        abort_if($missing, 403, 'You cannot give a role permissions you do not have yourself: '.implode(', ', $missing));
    }

    private function editable($actor, Role $r): bool
    {
        return $r->name !== self::LOCKED && ! $actor->hasRole($r->name)
            && collect($r->permissions->pluck('name'))->every(fn ($p) => $actor->can($p));
    }

    private function find(int $id): Role
    {
        return Role::where('tenant_id', tenant_id())->with('permissions')->findOrFail($id);
    }

    private function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
