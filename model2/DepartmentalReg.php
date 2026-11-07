<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepartmentalReg extends Model
{
    use HasFactory;

    protected $fillable = [

        'level',
        'course_id',
        'department_id',
        'title',
        'credit_load',
        'course_study_id',
        'semester_offered',
        'assigned_to',
        'vu_semester_id',
        'vu_session_id',
        'academic_session_id',

    ];

    public function course()
    {
        return $this->belongsTo(Course::class);

    }

    public function course_regs()
    {
        return $this->hasMany(CourseReg::class);
    }
    
}
