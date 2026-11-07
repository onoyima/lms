<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_reg_id',
        'course_id',
        'ca_one',
        'ca_two',
        'ca_three',
        'examination',
        'academic_session_id',
        'vu_semester_id',
        'course_department_id',
        'department_id',

    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function course_reg()
    {
        //return $this->hasOne(model, foreign_id, primary_id);
        return $this->hasOne(CourseReg::class);
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }
}
