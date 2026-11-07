<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseGradingSystem extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_study_id',
        'course_id',
        'grading_category_id'
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function grading_category()
    {
        return $this->belongsTo(GradingCategory::class);
    }
}
