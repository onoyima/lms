<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SbcRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'vu_semester_id',
        'level',
        'course_study_id',
        'comment',
    ];
}
