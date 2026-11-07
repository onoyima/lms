<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JambUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'reg_no',
        'fname',
        'mname',
        'lname',
        'gender',
        'state',
        'agg',
        'course',
        'course_study_id',
        'vu_session_id',
        'exam_year',
        'is_check_jamb',
    ];

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

}
