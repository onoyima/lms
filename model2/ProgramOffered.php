<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramOffered extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abb',
        'code',
        'programme_certification_id',
        'status',
    ];

    public function programme_certification()
    {
        //return this->belongsTo('App\Models\User')
        return $this->belongsTo(ProgrammeCertification::class);
    }

    public function course_studies()
    {
        return $this->hasMany(CourseStudy::class);
    }
}
