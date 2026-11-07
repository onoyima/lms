<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RequirementOne extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'course_study_id',
        'admissions_type_id',
        'jamb_score',
        'requirement_des',
    ];

}
