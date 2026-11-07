<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One saved state of an institution's settings; rows are never changed, only added (Target 170). */
class SettingsVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['scope', 'data', 'user_id', 'note', 'restored_from'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
