<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoursePoll extends Model
{
    use HasFactory;

    protected $fillable = [

        'level',
        'course_id',
        'department_id',
        'course_study_id',
        'course_category_id',
        'semester_offered',
        'assigned_to',
        'academic_session_id',

    ];

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function study_course()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    public function course_category()
    {
        return $this->belongsTo(CourseCategory::class);
    }

}
