<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CourseSection extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'title', 'summary', 'position', 'release_at', 'visible'];

    protected function casts(): array
    {
        return ['release_at' => 'datetime', 'visible' => 'boolean'];
    }

    public function offering(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourseItem::class);
    }
}
