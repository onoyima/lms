<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change to an institution's subscription (history, disputes, analytics). */
class SubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'subscription_id', 'type', 'data', 'user_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(int $tenantId, string $type, array $data = [], ?int $subscriptionId = null): self
    {
        return self::create(['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId, 'type' => $type, 'data' => $data ?: null,
            'user_id' => auth()->id()]);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
