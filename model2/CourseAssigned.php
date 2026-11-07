<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseAssigned extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'department_id',
        'course_id',
        'staff_type',
        'academic_session_id',
        'vu_session_id',
        'vu_semester_id',
        'assign_type',
        'assigner_id',
        'date_submitted',
        'date_approved',
        'status',
    ];

    public function assigned_programs()
    {
        return $this->hasMany(AssignedProgram::class);
    }

    public function course_regs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
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
