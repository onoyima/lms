<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abb',
        'faculty_id'
    ];

    public function faculty()
    {
        //return this->belongsTo('App\Models\User')
        return $this->belongsTo(Faculty::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    public function other_fee_trans()
    {
        return $this->hasMany(OtherFeeTran::class);
    }

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function course_studies()
    {
        return $this->hasMany(CourseStudy::class);
    }

    public function assigned_course()
    {
        return $this->hasOne(AssignedCourse::class);
    }

    public function course_poll()
    {
        return $this->hasMany(CoursePoll::class);
    }

    public function course_regs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function student_academics()
    {
        return $this->hasMany(StudentAcademic::class);
    }

    public function staff_work_profiles()
    {
        return $this->hasMany(StaffWorkProfile::class);
    }

    public function staff_promotions()
    {
        return $this->hasMany(StaffPromotion::class);
    }



}

