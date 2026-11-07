<?php

namespace App\Services\Exams;

use App\Models\Exam;
use App\Models\ExamComputer;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hall computers approved by IP address, and the address the exam server is reached on.
 *
 * When an exam asks for approved computers, the first request from a computer puts its IP on
 * the exams office's list as "waiting"; the candidate can start once it is approved. The list
 * is wiped after each sitting (or with the reset button) so the next exam starts clean, and
 * the wiped rows stay as a trace of which computers were allowed or refused.
 */
class ExamComputers
{
    public const DEFAULTS = ['preferred_address' => null, 'auto_clear' => true];

    public static function settings(?Tenant $tenant = null): array
    {
        $tenant ??= app(\App\Support\Tenancy\TenantContext::class)->get();

        return array_replace(self::DEFAULTS, (array) data_get($tenant?->settings, 'exam_network', []));
    }

    /** Record this computer's visit; a new one waits for a decision. */
    public function register(Request $request, ?User $user = null): ExamComputer
    {
        $ip = (string) $request->ip();
        $now = now();

        return DB::transaction(function () use ($ip, $request, $user, $now) {
            $row = ExamComputer::current()->where('ip', $ip)->lockForUpdate()->first();
            if (! $row) {
                return ExamComputer::create([
                    'ip' => $ip, 'status' => 'pending', 'last_user_id' => $user?->id, 'requests' => 1,
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'first_seen_at' => $now, 'last_seen_at' => $now,
                ]);
            }
            $row->forceFill([
                'last_user_id' => $user?->id ?? $row->last_user_id, 'last_seen_at' => $now, 'requests' => $row->requests + 1,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: $row->user_agent,
            ])->save();

            return $row;
        });
    }

    public static function required(Exam $exam, ExamSession $session, int $userId): bool
    {
        return $session->mode === 'hall' && (bool) ExamSettings::forCandidate($exam->proctoring, $userId, $exam->id)['ip_approval']
            && app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(app(\App\Support\Tenancy\TenantContext::class)->get(), 'cbt_halls');
    }

    /** Stop the start unless this computer is approved. */
    public function gate(Request $request, Exam $exam, ExamSession $session, User $user): void
    {
        if (! self::required($exam, $session, $user->id)) {
            return;
        }
        $computer = $this->register($request, $user);
        match ($computer->status) {
            'approved' => null,
            'rejected' => throw new ExamException("This computer ({$computer->ip}) is not allowed to sit exams. Move to another computer or speak to the invigilator.", 'computer_rejected', 403),
            default => throw new ExamException("This computer ({$computer->ip}) is waiting for approval by the exams office. Tell the invigilator, then press Start again.", 'computer_pending', 403),
        };
    }

    public function decide(ExamComputer $computer, string $status, int $by): void
    {
        $computer->forceFill(['status' => $status, 'decided_by' => $by, 'decided_at' => now()])->save();
    }

    /** Wipe the current list (kept as history). Only rows decided or first seen before $before, when given. */
    public function clear(?int $by, string $reason, ?\Carbon\CarbonInterface $before = null): int
    {
        return ExamComputer::current()
            ->when($before, fn ($q) => $q->where(fn ($w) => $w->where('decided_at', '<', $before)->orWhere(fn ($p) => $p->whereNull('decided_at')->where('first_seen_at', '<', $before))))
            ->update(['cleared_at' => now(), 'cleared_by' => $by, 'clear_reason' => mb_substr($reason, 0, 190), 'updated_at' => now()]);
    }

    /**
     * After a sitting ends (15 minutes' grace) and nothing else is running, wipe the approvals made
     * before it ended, so the next exam starts with a clean list. Runs from the scheduler.
     */
    public function autoClear(): int
    {
        $grace = now()->subMinutes(15);
        $running = ExamSession::query()->where('starts_at', '<=', now())->where('ends_at', '>', $grace)->exists();
        if ($running || ! self::settings()['auto_clear']) {
            return 0;
        }
        $ended = ExamSession::query()->where('ends_at', '<=', $grace)->where('ends_at', '>=', now()->subDays(7))
            ->whereIn('exam_id', Exam::query()->select('id'))->with('exam:id,title')->latest('ends_at')->first();
        if (! $ended) {
            return 0;
        }

        return $this->clear(null, 'Cleared automatically after "'.$ended->exam?->title.'" ended', $ended->ends_at);
    }

    /** IPv4 addresses this server answers on, best first (private campus addresses before others). */
    public static function serverAddresses(Request $request): array
    {
        $found = [];
        $add = function (?string $ip, string $source) use (&$found) {
            if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
                return;
            }
            $found[$ip] ??= ['ip' => $ip, 'source' => $source, 'private' => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)];
        };
        $add($request->server('SERVER_ADDR'), 'this request');
        $host = gethostname();
        foreach (($host ? gethostbynamel($host) : false) ?: [] as $ip) {
            $add($ip, 'network card');
        }
        if (filter_var($request->getHost(), FILTER_VALIDATE_IP)) {
            $add($request->getHost(), 'address in the browser');
        }
        $list = array_values($found);
        usort($list, fn ($a, $b) => [$b['private'], $a['source'] === 'this request'] <=> [$a['private'], $b['source'] === 'this request']);

        return $list;
    }

    /** Save the address students should use and make sure the platform answers for this institution on it. */
    public function setPreferredAddress(Tenant $tenant, ?string $address): void
    {
        $settings = (array) $tenant->settings;
        $domains = collect($tenant->domains ?? [])->map(fn ($d) => strtolower($d));
        $added = data_get($settings, 'exam_network.added_domain');
        if ($added && strtolower($added) !== strtolower((string) $address)) {
            $domains = $domains->reject(fn ($d) => $d === strtolower($added)); // only remove what this page added
            $added = null;
        }
        if ($address && ! $domains->contains(strtolower($address))) {
            $domains->push(strtolower($address));
            $added = $address;
        }
        $settings['exam_network'] = array_replace(self::settings($tenant), ['preferred_address' => $address, 'added_domain' => $added]);
        $tenant->forceFill(['settings' => $settings, 'domains' => $domains->unique()->values()->all()])->save();
        Cache::forget('tenants:host-map');
    }
}
