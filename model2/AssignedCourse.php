<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssignedCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'staff_type',
        'course_id',
        'department_id',
        'course_study_id',
        'academic_session_id',
        'vu_session_id',
        'vu_semester_id',
        'level',
        'course_category_id',
        'credit_load',
        'course_approval_id',
        'is_approval_stage',
        'hod_approval',
        'dean_approval',
        'sbc_approval',
        'vc_senate_approval',
        'title',
        'course_approval_id',
        'approval_status',
        'upload_status',
    ];

    protected $casts = [

        'assigned_to' => 'array',

    ];

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

    public function staff_work_profile()
    {
        return $this->belongsTo(StaffWorkProfile::class, 'staff_id', 'staff_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function course_regs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function course_reg_students()
    {
        return $this->hasMany(CourseReg::class);
    }


}
