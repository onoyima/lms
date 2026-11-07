<?php

namespace App\Services\Directory;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\Connectors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in with portal details on the platform's own sign-in page, for institutions whose records system is
 * connected (Integrations > University portal, "people sign in with their portal ID and password").
 * The portal checks the password; the platform never stores it. Only tried for people who are not a local
 * account here or who came from the portal — a local administrator's password is never sent anywhere.
 */
class PortalSignIn
{
    public function __construct(private Connectors $connectors, private DirectorySync $sync, private AuditLogger $audit) {}

    public function enabled(): bool
    {
        $c = $this->connectors->get('directory');

        return tenant_id() !== null && $c['enabled'] && $c['sso'] && $c['url'] !== '' && $c['client_id'] !== '' && $c['secret'] !== '';
    }

    /** Should the portal be asked about this login? */
    public function appliesTo(?User $local): bool
    {
        if (! $this->enabled() || ($local && $local->isPlatformAdmin())) {
            return false;
        }

        return ! $local || DB::table('legacy_map')->where('tenant_id', tenant_id())->whereIn('kind', ['dir.staff', 'dir.student', 'dir.applicant'])->where('new_id', $local->id)->exists();
    }

    /** The signed-in account, or null when the portal does not recognise the details. Clear reasons are shown on the form. */
    public function attempt(string $login, string $password, string $field): ?User
    {
        try {
            $answer = DirectoryClient::fromSettings($this->connectors->get('directory'))->post('authenticate', ['login' => $login, 'password' => $password]);
        } catch (DirectoryException $e) {
            Log::warning('portal sign-in unavailable: '.$e->getMessage(), ['tenant' => tenant_id()]);
            throw ValidationException::withMessages([$field => 'The university portal cannot be reached right now, so portal accounts cannot sign in. Please try again in a few minutes.']);
        }

        switch ($answer['result'] ?? 'invalid') {
            case 'ok':
                try {
                    $user = $this->sync->upsertOne((array) $answer['data']);
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages([$field => $e->getMessage()]);
                }
                if ($user->status !== 'active') {
                    throw ValidationException::withMessages([$field => 'This account has been suspended here. Please contact the registry.']);
                }
                SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => $user->id, 'type' => 'login_portal', 'ip' => request()->ip(), 'path' => 'login',
                    'data' => ['subject' => $answer['data']['subject'] ?? null], 'created_at' => now()]);

                return $user;
            case 'inactive':
                throw ValidationException::withMessages([$field => ($answer['type'] ?? '') === 'applicant'
                    ? 'Your application is not open for this session. Please contact the admissions office.'
                    : 'Your portal account is not active for exams and learning (status: '.($answer['status'] ?? 'inactive').'). Please contact the registry.']);
            case 'locked':
                throw ValidationException::withMessages([$field => 'Too many wrong passwords for this account. Try again in '.max(1, (int) ceil(($answer['retry_after'] ?? 60) / 60)).' minute(s).']);
            default:
                return null;
        }
    }
}
