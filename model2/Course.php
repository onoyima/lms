<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    // status: 0 = Unpublished Course; 1 = Publish course
    //is_assigned: 0 = Not assigned;  1 = Assigned; 2 = Cancelled

    use HasFactory;

    protected $fillable = [
        'title',
        'code',
        'department_id',
        'course_study_id',
        'credit_load',
        'course_category_id',
        'level',
        'programme_id',
        'summer_max_grade',
        'semester_offered',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);

    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);

    }

    public function departmental_regs()
    {
        return $this->hasMany(DepartmentalReg::class);
    }

    public function course_grading_system()
    {
        return $this->hasMany(CourseGradingSystem::class);
    }

    public function course_assigneds()
    {
        return $this->hasMany(CourseAssigned::class);
    }

    public function assigned_programs()
    {
        return $this->hasMany(AssignedProgram::class);
    }

    public function registration_process()
    {
        return $this->hasMany(RegistrationProcess::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function assigned_courses()
    {
        return $this->hasMany(AssignedCourse::class);
    }

    public function semester_remark_course()
    {
        return $this->hasMany(SemesterRemarkCourse::class);
    }

    public function course_polls()
    {
        return $this->hasMany(CoursePoll::class);
    }

    public function course_category()
    {
        return $this->belongsTo(CourseCategory::class);
    }

    public function course_registration()
    {
        return $this->hasOne(CourseReg::class);
    }

}
