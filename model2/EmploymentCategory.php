<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmploymentCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name'
    ];

    public function staff_work_profile()
    {
        return $this->hasOne(StaffWorkProfile::class);
    }

    public function staff_employment_categories()
    {
        return $this->hasMany(StaffEmploymentCategory::class);
    }

}
