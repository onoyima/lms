<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManageCourseReg extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_session_id',
        'vu_session_id',
        'start_date',
        'end_date',
    ];

    public function manage_course_reg_studies()
    {
        return $this->hasMany(ManageCourseRegStudy::class);
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
