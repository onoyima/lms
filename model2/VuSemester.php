<?php

namespace App\Models;

use App\Http\Livewire\Staff\Sbc\GroupMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VuSemester extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_session_id',
        'semester_id',
        'status',
    ];

    public function dept_transfers()
    {
        return $this->hasMany(DeptTransfer::class);
    }

    public function course_register_log()
    {
        return $this->hasMany(CourseRegisterLog::class);
    }

    public function course_assigneds()
    {
        return $this->hasMany(CourseAssigned::class);
    }

    public function student_academics()
    {
        return $this->hasMany(StudentAcademic::class);
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function result_approval_process()
    {
        return $this->hasMany(ResultApprovalProcess::class);
    }

    public function registration_process()
    {
        return $this->hasMany(RegistrationProcess::class);
    }

    public function registration_approval()
    {
        return $this->hasMany(RegistrationApproval::class);
    }

    public function courseRegs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function semester()
    {
        //return this->belongsTo('App\Models\User')
        return $this->belongsTo(Semester::class);
    }

    public function assigned_courses()
    {
        return $this->hasMany(AssignedCourse::class);
    }

    public function approvedResults()
    {
        return $this->hasMany(ApprovedResult::class);
    }

    public function result_correction()
    {
        return $this->hasMany(ResultCorrection::class);
    }

    public function correction_requests()
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function sbc_groups()
    {
        return $this->hasMany(SbcGroup::class);
    }

    public function studentship_histories()
    {
        return $this->hasMany(StudentshipHistory::class, 'start_semester');
    }

}
