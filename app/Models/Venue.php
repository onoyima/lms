<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    protected static function booted(): void
    {
        // a campus not used before takes one of the subscription's campuses
        static::saving(function (Venue $v) {
            $campus = trim((string) $v->campus);
            if ($campus !== '' && ($v->isDirty('campus') || ! $v->exists)
                && ! static::where('campus', $campus)->when($v->exists, fn ($q) => $q->where('id', '!=', $v->id))->exists()) {
                app(\App\Services\Billing\Entitlements::class)->check('campuses');
            }
        });
    }

    use BelongsToTenant;

    protected $fillable = ['name', 'campus', 'capacity', 'network_cidr'];

    public function seats(): HasMany { return $this->hasMany(Seat::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }
}
