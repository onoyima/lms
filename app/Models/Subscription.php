<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-owned record (not scoped to an institution's data). */
class Subscription extends Model
{
    protected $fillable = ['tenant_id', 'plan_id', 'cycle', 'status', 'price', 'currency', 'student_limit', 'limits', 'note', 'current_period_start', 'current_period_end', 'trial_ends_at', 'cancel_at_period_end'];

    protected function casts(): array
    {
        return ['current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'trial_ends_at' => 'datetime', 'cancel_at_period_end' => 'boolean', 'price' => 'integer', 'limits' => 'array'];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
