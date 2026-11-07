<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Faculty extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'abb',
        'code', // Paystack subaccount code (ACCT_xxx)
    ];

    protected static $logAttributes = ['name', 'abb'];

    // Where fillable is not define but guard is define
    //protected static $logUnguarded = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'abb'])
                ->logOnlyDirty()->useLogName('faculty')->dontSubmitEmptyLogs();
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function staff_work_profiles()
    {
        return $this->hasMany(Staff::class);
    }
}

