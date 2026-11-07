<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManageCourseRegStudy extends Model
{
    use HasFactory;

    protected $fillable = [
        'manage_course_reg_id',
        'academic_session_id',
        'vu_session_id',
        'course_study_id',
        'start_date',
        'end_date',
    ];

    public function manage_course_reg()
    {
        return $this->belongsTo(ManageCourseReg::class);
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

}
