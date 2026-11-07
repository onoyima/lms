<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SemesterRemarkCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_session_id',
        'course_id',
        'student_id',
        'type',
        'status'
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
    
}
