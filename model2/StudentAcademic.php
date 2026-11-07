<?php

namespace App\Models;

use App\Models\AdmissionsType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class StudentAcademic extends Model
{

    //studentship 1=normal, 2=deferement, 3 = self withdrawn, 4=expelled, 5=suspended

    use HasFactory, LogsActivity;

    public static $customCauser = null;

    public static $causerDepartment = null;

    protected $fillable = [
        'student_id',
        'student_type',
        'entry_mode_id',
        'study_mode_id',
        'matric_no',
        'old_matric_no',
        'course_study_id',
        'level',
        'entry_session_id',
        'vu_semester_id',
        'academic_session_id',
        'first_semester_load',
        'second_semester_load',
        'summer_max',
        'lowest_unit',
        'highest_unit',
        'program_type',
        'tc',
        'tgp',
        'jamb_no',
        'jamb_score',
        'last_registration',
        'summer',
        'studentship',
        // Legacy column name used by provisioning / ProcessOneController creates.
        'studentships',
        'studentship_id',
        'admissions_type_id',
        'faculty_id',
        'department_id',
        'acad_status_id',
        'admitted_date',
        'is_hostel',
    ];

    protected static $logAttributes = [
        'student_id',
        'student_type',
        'entry_mode_id',
        'study_mode_id',
        'matric_no',
        'old_matric_no',
        'course_study_id',
        'level',
        'entry_session_id',
        'vu_semester_id',
        'academic_session_id',
        'first_semester_load',
        'second_semester_load',
        'summer_max',
        'lowest_unit',
        'highest_unit',
        'program_type',
        'tc',
        'tgp',
        'jamb_no',
        'jamb_score',
        'last_registration',
        'summer',
        'studentship',
        'studentship_id',
        'admissions_type_id',
        'faculty_id',
        'department_id',
        'acad_status_id',
        'admitted_date'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll() // Log all attributes of the model
            ->logOnlyDirty() // Log only changes to attributes
            ->useLogName('student_academic') // Specify log name
            ->dontSubmitEmptyLogs(); // Prevent empty logs from being submitted
    }

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function dept_transfers()
    {
        return $this->hasMany(DeptTransfer::class);
    }

    public function course_reg()
    {
        return $this->hasOne(CourseReg::class, 'student_id', 'student_id');
    }

    public function registration_approval()
    {
        return $this->hasMany(RegistrationApproval::class, 'student_id', 'student_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    public function course_register_logs()
    {
        return $this->hasMany(CourseRegisterLog::class, 'student_id', 'student_id');
    }

    public function approved_results()
    {
        return $this->hasMany(ApprovedResult::class, 'student_id', 'student_id');
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }

    public function studentship()
    {
        return $this->belongsTo(Studentship::class);
    }

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

/*     public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    } */

}
