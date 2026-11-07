<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuxiliaryStaff extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'department_id',
        'assigned_by'
    ];

    public function staff_work_profile()
    {
        return $this->belongsTo(StaffWorkProfile::class, 'staff_id', 'staff_id');
    }
}
