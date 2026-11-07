<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffPromotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'department_id',
        'admin_department_id',
        'staff_position_id',
        'grade_level_id',
        'staff_step_id',
        'promoted_date',
    ];

    public function grade_level()
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function staff_step()
    {
        return $this->belongsTo(StaffStep::class);
    }

    public function staff_work_profile()
    {
        return $this->belongsTo(StaffWorkProfile::class, 'staff_id', 'staff_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function admin_department_id()
    {
        return $this->belongsTo(AdminDepartment::class);
    }

    public function staff_position()
    {
        return $this->belongsTo(StaffPosition::class);
    }
}
