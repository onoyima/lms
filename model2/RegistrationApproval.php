<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrationApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'staff_id',
        'vu_semester_id',
        'level',
        'faculty_id',
        'department_id',
        'course_study_id',
        'category',
        'approved_date',
        'comment',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function student_academic()
    {
        return $this->belongsTo(StudentAcademic::class, 'student_id', 'student_id');
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }
}
