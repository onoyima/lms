<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClassMeeting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'held_at', 'topic', 'code', 'code_expires_at'];

    protected function casts(): array
    {
        return ['held_at' => 'datetime', 'code_expires_at' => 'datetime'];
    }

    public function records(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
