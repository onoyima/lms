<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GradeItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'grade_category_id', 'source', 'source_id', 'title', 'max_score'];

    protected function casts(): array
    {
        return ['max_score' => 'float'];
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(GradeCategory::class, 'grade_category_id');
    }

    public function entries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GradeEntry::class);
    }
}
