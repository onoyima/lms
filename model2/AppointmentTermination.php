<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentTermination extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'department_id',
        'admin_department_id',
        'termination_id',
        'description',
        'date_terminated',
    ];

    public function staff_work_profile()
    {
        return $this->belongsTo(StaffWorkProfile::class, 'staff_id', 'staff_id');
    }

    public function termination()
    {
        return $this->belongsTo(Termination::class);
    }
}
