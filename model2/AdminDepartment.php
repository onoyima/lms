<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminDepartment extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'parent_id',
        'hod_id',
        'academic_department_id',
        'status',
    ];

    public function staff_work_profiles()
    {
        return $this->hasMany(StaffWorkProfile::class);
    }

    public function staff_promotions()
    {
        return $this->hasMany(StaffPromotion::class);
    }
}

