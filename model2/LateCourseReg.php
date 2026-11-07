<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LateCourseReg extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'other_fee_id',
        'other_fee_history_id',
        'vu_session_id',
    ];

}
