<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A platform administrator signed in as an institution user for support (platform-owned record). */
class SupportSession extends Model
{
    protected $fillable = ['platform_user_id', 'tenant_id', 'target_user_id', 'reason', 'ip', 'started_at', 'expires_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function live(): bool
    {
        return $this->started_at && ! $this->ended_at && $this->expires_at?->isFuture();
    }
}
