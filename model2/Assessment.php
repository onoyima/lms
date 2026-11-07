<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'ca_one',
        'ca_two',
        'ca_three',
        'examination',
        'academic_session_id'
    ];

}
