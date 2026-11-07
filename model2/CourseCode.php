<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'department_id',
        'course_study_id',
    ];

}
