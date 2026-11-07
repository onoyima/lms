<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'vu_semester_id',
        'level_id',
        'department_id',
        'course_study_id'
    ];
}
