<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

//A scholarship body is been define here.

class ScholarshipBody extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone_no',
        'percentage',
        'status',
    ];

    public function scholarship_funds()
    {
        return $this->hasMany(ScholarshipFund::class);
    }

    public function scholarship_students()
    {
        return $this->hasMany(ScholarshipStudent::class);
    }

}
