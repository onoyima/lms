<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffEmploymentCategory extends Model
{
    use HasFactory;

    protected $fillable = [

        'staff_id',
        'employment_category_id',
        'department_id',
        'admin_department_id',
        'date_changed'

    ];

    public function staff_work_profile()
    {
        return $this->belongsTo(StaffWorkProfile::class, 'staff_id', 'staff_id');
    }

    public function employment_category()
    {
        return $this->belongsTo(EmploymentCategory::class);
    }
}
