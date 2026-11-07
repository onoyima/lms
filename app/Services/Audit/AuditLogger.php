<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes the tamper-evident audit trail (Target items 38, 89, 110, 198, 219).
 * hash = sha256(prev_hash | tenant | actor | action | subject | data | created_at)
 * Each institution has its own chain; platform-level events use the NULL-tenant chain.
 */
class AuditLogger
{
    public function __construct(private TenantContext $context) {}

    public function record(string $action, ?Model $subject = null, array $data = [], ?int $actorId = null): AuditLog
    {
        $tenantId = $this->context->id();
        $actorId ??= auth()->id();
        // everything done during a support sign-in is marked as done by the platform team
        if (($sid = request()?->hasSession() ? request()->session()->get('support_session_id') : null) && ! isset($data['support_session'])) {
            $data['support_session'] = $sid;
        }

        return DB::transaction(function () use ($action, $subject, $data, $actorId, $tenantId) {
            $prev = AuditLog::query()
                ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('hash');

            $row = [
                'tenant_id' => $tenantId,
                'actor_id' => $actorId,
                'action' => $action,
                'subject_type' => $subject ? $subject->getMorphClass() : null,
                'subject_id' => $subject?->getKey(),
                'data' => $data ?: null,
                'ip' => request()?->ip(),
                'prev_hash' => $prev,
                'created_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
            ];
            $row['hash'] = self::hash($row);

            return AuditLog::create($row);
        });
    }

    public static function hash(array $row): string
    {
        $data = $row['data'] ?? null;
        if (is_array($data)) {
            ksort($data);
        }

        return hash('sha256', implode('|', [
            $row['prev_hash'] ?? '',
            $row['tenant_id'] ?? '',
            $row['actor_id'] ?? '',
            $row['action'],
            $row['subject_type'] ?? '',
            $row['subject_id'] ?? '',
            $data === null ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $row['created_at'] instanceof \DateTimeInterface ? $row['created_at']->format('Y-m-d H:i:s.u') : $row['created_at'],
        ]));
    }

    /**
     * Re-compute every hash in a chain. Returns the id of the first broken row, or null if intact.
     */
    public function verify(?int $tenantId): ?int
    {
        $prev = null;
        $broken = null;

        AuditLog::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$prev, &$broken) {
                foreach ($rows as $log) {
                    $row = $log->getAttributes();
                    $row['data'] = $log->data;
                    if ($log->prev_hash !== $prev || ! hash_equals($log->hash, self::hash($row))) {
                        $broken = $log->id;

                        return false;
                    }
                    $prev = $log->hash;
                }
            });

        return $broken;
    }
}
