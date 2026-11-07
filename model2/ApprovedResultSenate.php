<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovedResultSenate extends Model
{
    use HasFactory;

    protected $casts = [
        'basic_info' => 'array',
        'bundle_array' => 'array',
        'offered_courses'=> 'array',
        'course_summary' => 'array',
        'student_mark' => 'array',
        'grade_distribution' => 'array',
        'session_list' => 'array',
    ];

    protected $fillable = [
        'course_study_id',
        'academic_session_id',
        'vu_semester_id',
        'level',
        'basic_info',
        'bundle_array',
        'offered_courses',
        'course_summary',
        'student_mark',
        'grade_distribution',
        'session_list',
    ];

}
