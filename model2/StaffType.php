<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name'
    ];

    public function staff_work_profiles()
    {
        return $this->hasMany(StaffWorkProfile::class);
    }

}
