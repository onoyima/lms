<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssignedProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_assigned_id',
        'department_id',
        'course_study_id',
        'vu_semester_id',
        'course_id',
        'level',
        'approval_stage',
        'student_ids',
        'date_submitted',
        'date_approved',
        'status'
    ];

    public function course_assigned()
    {
        return $this->belongsTo(CourseAssigned::class);

    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);

    }

}
