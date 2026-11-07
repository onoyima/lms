<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    protected static function booted(): void
    {
        // subscription capacity
        static::creating(fn () => app(\App\Services\Billing\Entitlements::class)->check('faculties'));
    }

    use BelongsToTenant;

    protected $fillable = ['name', 'code', 'external_id'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
