<?php

//is_course_reg:
// 0 = added_to_poll_but_not_registered; //NO LONGER APPLICABLE
// 1 = Selected courses from a level poll for semesterial registration; //NO LONGER APPLICABLE
// 2 = registered
// 3 = deleted courses
// 4 = voided for departmental transfer / programme change

//is_carry_over:
// 1 = first carry over
// 2 = registered carryover
// 3 = Re-registered carryover that need not to be re-registered but can only be used for computation
// 4 = Passed carryover

//Column to be added
//correction 1 = Approved for correction by VC; 2 = SBC; 3=AU; 4=Dean; 5=Department; 6=Lecturer
// 11 = Correction Approved by VC; 10 = SBC; 9=AU; 8=Dean; 7=Department;

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CourseReg extends Model
{
    use HasFactory, LogsActivity;

    public static $customCauser = null;

    public static $causerDepartment = null;

    public static $dropReason = null;

    protected $fillable = [
        'student_id',
        'course_id',
        'level',
        'academic_session_id',
        'vu_semester_id',
        'vu_session_id',
        'course_assigned_id',
        'course_register_log_id',
        'assigned_course_id',
        'ca_one',
        'ca_two',
        'ca_three',
        'examination',
        'total',
        'grade',
        'course_deprtment_id',
        'staff_id',
        'offer_method',   //How is the course taught 1=general 2=departments 3=groups
        'department_id',
        'course_study_id',
        'semester_offered',
        'is_course_reg',
        'is_course_pass',
        'is_carryover',
        'is_correction',
        'is_vc_approval',
        'status',
    ];

    protected static $logAttributes = ['ca_one', 'ca_two', 'ca_three', 'examination'];

    // where fillable is not define but guard is define
    //protected static $logUnguarded = true;

/*     public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['ca_one', 'ca_two', 'ca_three', 'examination'])
                ->logOnlyDirty()->useLogName('course_reg')->dontSubmitEmptyLogs();
    } */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll() // Log all attributes of the model
            ->logOnlyDirty() // Log only changes to attributes
            ->useLogName('course_reg') // Specify log name
            ->dontSubmitEmptyLogs(); // Prevent empty logs from being submitted
    }

    public function course_assigned()
    {
        return $this->belongsTo(CourseAssigned::class);
    }


    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function assigned_course()
    {
        return $this->belongsTo(AssignedCourse::class);
    }
    public function departmental_reg()
    {
        return $this->belongsTo(DepartmentalReg::class);
    }

    public function student_academic()
    {
        return $this->belongsTo(StudentAcademic::class, 'student_id', 'student_id');
    }

    public function result_correction()
    {
        return $this->belongsTo(ResultCorrection::class);
    }

    public function correction_requests()
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function course_reg_log()
    {
        return $this->belongsTo(CourseRegisterLog::class);
    }

    public function assigned_course_staff()
    {
        return $this->belongsTo(AssignedCourse::class);
    }





}
