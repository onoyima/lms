<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultApprovalProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'vu_semester_id',
        'level',
        'faculty_id',
        'department_id',
        'course_study_id',
        'approved_date'
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }
}
