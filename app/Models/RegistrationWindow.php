<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RegistrationWindow extends Model
{
    use BelongsToTenant;

    protected $fillable = ['semester_id', 'opens_at', 'closes_at', 'late_closes_at', 'min_units', 'max_units', 'requires_approval'];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'late_closes_at' => 'datetime', 'requires_approval' => 'boolean'];
    }

    public function semester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
