<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class StaffWorkProfile extends Model
{
    use HasFactory, LogsActivity;

    public static $customCauser = null;

    public static $causerDepartment = null;

    protected $fillable = [
        'staff_id',
        'staff_no',
        'staff_type_id',
        'faculty_id',
        'department_id',
        'admin_department_id',
        'staff_position_id',
        'appointment_date',
        'assumption_date',
        'employment_category_id',
        'grade', // veritas
        'grade_level_id',
        'staff_step_id',
        'step_id',
    ];

    protected static $logAttributes = [
        'staff_id',
        'staff_no',
        'staff_type_id',
        'faculty_id',
        'department_id',
        'admin_department_id',
        'staff_position_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll() // Log all attributes of the model
            ->logOnlyDirty() // Log only changes to attributes
            ->useLogName('staff_work_profile') // Specify log name
            ->dontSubmitEmptyLogs(); // Prevent empty logs from being submitted
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }


    public function auxiliary_staff()
    {
        return $this->hasMany(AuxiliaryStaff::class, 'staff_id', 'staff_id');
    }

    public function stafftype()
    {
        return $this->belongsTo(StaffType::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function admin_department()
    {
        return $this->belongsTo(AdminDepartment::class);
    }

    public function staff_position()
    {
        return $this->belongsTo(StaffPosition::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function assigned_courses()
    {
        return $this->hasMany(AssignedCourse::class, 'staff_id', 'staff_id');
    }

    public function appointment_termination()
    {
        return $this->hasOne(AppointmentTermination::class, 'staff_id', 'staff_id');
    }


    public function staff_leave_summaries()
    {
        return $this->hasMany(StaffLeaveSummary::class, 'staff_id', 'staff_id');
    }

    public function staff_promotions()
    {
        return $this->hasMany(StaffPromotion::class, 'staff_id', 'staff_id');
    }

    public function employment_category()
    {

        return $this->belongsTo(EmploymentCategory::class);
    }

    public function staff_employment_categories()
    {
        return $this->hasMany(StaffEmploymentCategory::class, 'staff_id', 'staff_id');
    }

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

}
