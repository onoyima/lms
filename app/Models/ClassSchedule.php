<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClassSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'weekday', 'starts_at', 'ends_at', 'kind', 'venue', 'group_label', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'weekday' => 'integer'];
    }
}
