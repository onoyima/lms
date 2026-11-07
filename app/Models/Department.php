<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected static function booted(): void
    {
        // subscription capacity
        static::creating(fn () => app(\App\Services\Billing\Entitlements::class)->check('departments'));
    }

    use BelongsToTenant;

    protected $fillable = ['faculty_id', 'name', 'code', 'external_id'];

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
