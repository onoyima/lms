<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistrationProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'academic_session_id',
        'vu_semester_id',
        'level',
        'course_study_id',
        'category',
        'level_reg', // This are the course for the new level registration. 1=Registered 0=Not Registered
        'status',       //Main course registration 1 = Registered 0 = Not Registered
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }
}
