<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovedResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'level',
        'course_study_id',
        'academic_session_id',
        'vu_semester_id',
        'vu_session_id',
        'remark',
        'cgpa',
        'approved_date',
    ];

    public function student_academic()
    {
        return $this->belongsTo(StudentAcademic::class, 'student_id', 'student_id');
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }


}

