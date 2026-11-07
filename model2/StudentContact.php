<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class StudentContact extends Model
{
    use HasFactory, LogsActivity;

    public static $customCauser = null;

    public static $causerDepartment = null;

    protected $fillable = [
        'student_id',
        'title',
        'surname',
        'other_names',
        'relationship',
        'address',
        'state',
        'city',
        'phone_no',
        'phone_no_two',
        'email',
        'email_two',
    ];

    protected static $logAttributes = [
        'student_id',
        'title',
        'surname',
        'other_names',
        'relationship',
        'address',
        'state',
        'city',
        'phone_no',
        'phone_no_two',
        'email',
        'email_two',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll() // Log all attributes of the model
            ->logOnlyDirty() // Log only changes to attributes
            ->useLogName('student_contact') // Specify log name
            ->dontSubmitEmptyLogs(); // Prevent empty logs from being submitted
    }

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

}
