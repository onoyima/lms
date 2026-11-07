<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Programme extends Model
{
    use HasFactory;

    protected $fillable = [
        'name'
    ];

    public function admissions_types()
    {
        return $this->hasMany(AdmissionsType::class);
    }

    public function academic_session()
    {
        return $this->hasOne(AcademicSession::class);
    }

    public function assigned_course()
    {
        return $this->hasOne(AssignedCourse::class);
    }

}
