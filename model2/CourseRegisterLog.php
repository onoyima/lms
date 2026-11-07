<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseRegisterLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'academic_session_id',
        'vu_session_id',
        'vu_semester_id',
        'semester',
        'level',
        'department_id',
        'course_study_id',
        'excess_credit',
    ];

    public function student_academic()
    {
        //return $this->hasOne(model, foreign_id, primary_id);
        return $this->belongsTo(StudentAcademic::class, 'student_id', 'student_id');
    }

    public function course_regs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
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
