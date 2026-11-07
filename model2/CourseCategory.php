<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function course()
    {
        return $this->hasMany(Course::class);
    }

    public function course_polls()
    {
        return $this->hasMany(CoursePoll::class);
    }
}


