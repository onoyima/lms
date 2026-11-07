<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeptTransfer extends Model
{
    use HasFactory;

        protected $fillable = [
        'student_id',
        'bursary_staff_id',
        'staff_id',
        'old_course_study_id',
        'course_study_id',
        'old_level',
        'level',
        'vu_session_id',
        'vu_semester_id',

    ];

    public function student_academic()
    {
        return $this->belongsTo(StudentAcademic::class, 'student_id', 'student_id');
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

}
