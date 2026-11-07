<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_current' => 'boolean'];
    }

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }
}
