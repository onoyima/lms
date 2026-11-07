<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AccommodationProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'extra_time_percent', 'settings', 'approved_reference', 'approved_by', 'valid_until'];

    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
            'valid_until' => 'date',
        ];
    }
}
