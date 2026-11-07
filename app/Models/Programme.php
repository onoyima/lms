<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Programme extends Model
{
    use BelongsToTenant;

    protected $fillable = ['department_id', 'name', 'code', 'duration_years', 'external_id'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
