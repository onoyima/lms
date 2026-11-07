<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AcademicSession extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'session',
        'batch',
        'programme_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class);
    }

    public function course_assigneds()
    {
        return $this->hasMany(CourseAssigned::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function vu_semester()
    {
        return $this->hasOne(VuSemester::class);
    }

    public function assigned_courses()
    {
        return $this->hasMany(AssignedCourse::class);
    }

    public function courseRegs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function approvedResults()
    {
        return $this->hasMany(ApprovedResult::class);
    }

    public function student_academics()
    {
        return $this->hasMany(StudentAcademic::class);
    }

    public function result_correction()
    {
        return $this->hasMany(ResultCorrection::class);
    }

    public function correction_requests()
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function manage_course_reg()
    {
        return $this->hasMany(ManageCourseReg::class);
    }

    public function manage_course_reg_studies()
    {
        return $this->hasMany(ManageCourseRegStudy::class);
    }

    public function course_register_log()
    {
        return $this->hasMany(CourseRegisterLog::class);
    }

    public function registration_approvals()
    {
        return $this->hasMany(RegistrationApproval::class);
    }




}
