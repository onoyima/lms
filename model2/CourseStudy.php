<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseStudy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abb',
        'department_id',
        'pgd_program_offered_id',
        'program_offered_id',
        'masters_program_offered_id',
        'duration',
        'jamb_cutoff',
        'max_load',
        'phd',
        'pgd',
        'msc',
        'p_masters'
    ];

    public function dept_transfers()
    {
        return $this->hasMany(DeptTransfer::class);
    }

    public function course_register_log()
    {
        return $this->hasMany(CourseRegisterLog::class);
    }

    public function department()
    {
        //return this->belongsTo('App\Models\User')
        return $this->belongsTo(Department::class);
    }

    public function program_offered()
    {
        return $this->belongsTo(ProgramOffered::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    public function assigned_programs()
    {
        return $this->hasMany(AssignedProgram::class);
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

    public function applicant_bio_datas()
    {
        return $this->hasMany(ApplicantBioData::class);
    }

    public function vuna_admission_pgs()
    {
        return $this->hasMany(VunaAdmissionPg::class);
    }

    public function vuna_admission_jupeps()
    {
        return $this->hasMany(VunaAdmissionJupep::class);
    }

    public function vuna_admission_sandwichs()
    {
        return $this->hasMany(VunaAdmissionSandwich::class);
    }

    public function student_academics()
    {
        return $this->hasMany(StudentAcademic::class);
    }

        public function course_studies()
    {
        return $this->hasMany(CourseStudy::class);
    }

    public function course_poll()
    {
        return $this->hasMany(CoursePoll::class);
    }

    public function vuna_tuition_fee()
    {
        return $this->hasOne(VunaTuitionFee::class);
    }

    public function program_processing()
    {
        return $this->hasMany(ProgramProcessing::class);
    }

    public function jamb_uploads()
    {
        return $this->hasMany(JambUpload::class);
    }
}
