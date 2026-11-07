<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradingCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_study_id',
        'name'
    ];

    public function course_grading_system()
    {
        return $this->hasMany(CourseGradingSystem::class);
    }
}
