<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name'
    ];

    public function staff_work_profiles()
    {
        return $this->hasMany(StaffWorkProfile::class);
    }

    public function staffs()
    {
        return $this->hasMany(Staff::class);
    }

    public function staff_promotions()
    {
        return $this->hasMany(StaffPromotion::class);
    }
}
