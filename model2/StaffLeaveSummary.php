<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffLeaveSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'staff_leave_id',
        'department_id',
        'admin_department_id',
        'leave_year',
        'start_date',
        'end_date',
        'description',
    ];

    public function staff_leave()
    {
        return $this->belongsTo(StaffLeave::class);
    }

    public function staff_work_profile()
    {
        return $this->belongsTo(StaffWorkProfile::class, 'staff_id', 'staff_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
