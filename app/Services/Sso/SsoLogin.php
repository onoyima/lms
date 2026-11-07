<?php

namespace App\Services\Sso;

use App\Models\SecurityEvent;
use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a verified identity from a sign-in provider into a local account (LMS 2-3; Target 219):
 * by a remembered link first, then by verified email (if allowed), then — only if the provider
 * allows it and the email domain is permitted — a new account. Suspended accounts never get in.
 */
class SsoLogin
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{subject: string, email: ?string, email_verified: bool, name: ?string, identifier: ?string} $who */
    public function resolve(SsoProvider $p, array $who): User
    {
        return DB::transaction(function () use ($p, $who) {
            $link = SsoIdentity::where('sso_provider_id', $p->id)->where('subject', $who['subject'])->first();
            $user = $link ? User::find($link->user_id) : null;
            $email = $who['email'] ? mb_strtolower(trim($who['email'])) : null;

            if (! $user && $email && $p->link_by_email) {
                if (! $who['email_verified']) {
                    throw new RuntimeException('Your sign-in provider has not verified your email address.');
                }
                $user = User::where('email', $email)->whereNotNull('tenant_id')->first(); // never platform staff
            }
            if (! $user && $p->auto_create) {
                if (! $email || ! Policy::emailAllowed($email)) {
                    throw new RuntimeException('Your email address is not one this institution accepts.');
                }
                $user = User::forceCreate([
                    'name' => mb_substr($who['name'] ?: Str::before($email, '@'), 0, 190), 'email' => $email,
                    'identifier' => $who['identifier'] ? mb_substr($who['identifier'], 0, 60) : null,
                    'user_type' => $this->userType($p, $who['attributes'] ?? []), 'status' => 'active', 'password' => Hash::make(Str::password(48)), 'email_verified_at' => now(),
                ]);
                $p->default_role && $user->assignRole($p->default_role);
                $this->audit->record('user.created_by_sso', $user, ['provider' => $p->slug]);
            }
            if (! $user) {
                throw new RuntimeException('No account here matches your sign-in. Ask the registry to add you.');
            }
            if ($user->status !== 'active') {
                throw new RuntimeException('This account is not active.');
            }
            $this->applyRoleRules($p, $user, $who['attributes'] ?? []);
            SsoIdentity::updateOrCreate(['sso_provider_id' => $p->id, 'subject' => $who['subject']], ['user_id' => $user->id, 'last_login_at' => now()]);
            SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => $user->id, 'type' => 'login_sso', 'ip' => request()->ip(), 'path' => 'sso/'.$p->slug,
                'data' => ['provider' => $p->slug], 'created_at' => now()]);

            return $user;
        });
    }

    /** Values of one attribute or claim, whatever shape the provider sends (string, list, nested "a.b"). */
    public static function values(array $attributes, string $name): array
    {
        $v = $attributes[$name] ?? data_get($attributes, $name);

        return array_map(fn ($x) => mb_strtolower(trim((string) $x)), array_filter(is_array($v) ? \Illuminate\Support\Arr::flatten($v) : [$v], fn ($x) => is_scalar($x)));
    }

    /** student or staff, from the attribute the institution chose (e.g. eduPersonAffiliation), else the provider default */
    private function userType(SsoProvider $p, array $attributes): string
    {
        if ($p->type_attribute) {
            $vals = self::values($attributes, $p->type_attribute);
            // applicants sit screening exams as candidates, like students
            if (array_intersect($vals, ['student', 'students', 'learner', 'applicant', 'applicants'])) {
                return 'student';
            }
            if (array_intersect($vals, ['staff', 'faculty', 'employee', 'teacher', 'lecturer'])) {
                return 'staff';
            }
        }

        return $p->default_user_type;
    }

    /**
     * Roles from the directory (LMS 2-3): each rule "attribute X contains value Y gives role Z" is applied
     * at every sign-in. Roles carrying institution-wide power are never given this way.
     */
    private function applyRoleRules(SsoProvider $p, User $user, array $attributes): void
    {
        $changed = self::syncRuleRoles($p, $user, $attributes);
        if ($changed['given'] || $changed['removed']) {
            $this->audit->record('user.roles_from_sso', $user, ['provider' => $p->slug] + $changed);
        }
    }

    /**
     * Roles named in the provider's rules follow the directory: given when a rule matches, taken away when
     * no rule for that role matches any more. A role is only taken away when every rule for it looks at an
     * attribute the directory actually sent, so a provider that stops sending groups removes nothing.
     * Roles with institution-wide power, and roles that no longer exist, are left alone.
     *
     * @return array{given: list<string>, removed: list<string>}
     */
    public static function syncRuleRoles(SsoProvider $p, User $user, array $attributes): array
    {
        $existing = array_flip(\App\Support\Roles::names());
        $byRole = [];
        foreach ((array) $p->role_rules as $rule) {
            $role = (string) ($rule['role'] ?? '');
            if ($role === '' || ! isset($existing[$role]) || \App\Support\Roles::privileged($role)) {
                continue;
            }
            $attr = (string) ($rule['attribute'] ?? '');
            $present = array_key_exists($attr, $attributes) || data_get($attributes, $attr) !== null;
            $match = $present && in_array(mb_strtolower(trim((string) ($rule['value'] ?? ''))), self::values($attributes, $attr), true);
            $byRole[$role]['match'] = ($byRole[$role]['match'] ?? false) || $match;
            $byRole[$role]['all_present'] = ($byRole[$role]['all_present'] ?? true) && $present;
        }
        $given = $removed = [];
        foreach ($byRole as $role => $r) {
            if ($r['match'] && ! $user->hasRole($role)) {
                $user->assignRole($role);
                $given[] = $role;
            } elseif (! $r['match'] && $r['all_present'] && $user->hasRole($role)) {
                $user->removeRole($role);
                $removed[] = $role;
            }
        }

        return ['given' => $given, 'removed' => $removed];
    }
}
