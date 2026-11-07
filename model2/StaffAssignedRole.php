<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class StaffAssignedRole extends Model
{
    use HasFactory, LogsActivity;

    public const DEPARTMENT_ROLES = ['HOD', 'Exam Officer', 'Level Adviser'];

    public const FACULTY_ROLES = ['Dean'];

    public const LEVEL_ROLES = ['Level Adviser'];

    public static $customCauser = null;

    public static $causerDepartment = null;

    protected $fillable = [
        'staff_id',
        'role_id',
        'assigner_role_id',
        'department_id',
        'faculty_id',
        'assigned_by',
        'removed_by',
        'assigned_date',
        'level',
        'removed_date',
    ];

    protected static $logAttributes = [
        'staff_id',
        'role_id',
        'assigner_role_id',
        'department_id',
        'faculty_id',
        'assigned_by',
        'removed_by',
        'assigned_date',
        'level',
        'removed_date',
    ];

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
            ->useLogName('staff_assigned_role') // Specify log name
            ->dontSubmitEmptyLogs(); // Prevent empty logs from being submitted
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }
}
