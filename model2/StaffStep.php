<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function staff_promotions()
    {
        return $this->hasMany(StaffPromotion::class);
    }

    public function staffs()
    {
        return $this->hasMany(Staff::class);
    }

}
