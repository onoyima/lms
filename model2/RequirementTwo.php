<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RequirementTwo extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'course_study_id',
        'secondary_subject_id',
        'admissions_type_id',
        'category',
        'secondary_grade_id'
    ];


    public function secondary_grade()
    {
        return $this->belongsTo(SecondaryGrade::class);
    }

    public function secondary_subject()
    {
        return $this->belongsTo(SecondarySubject::class);
    }

}
