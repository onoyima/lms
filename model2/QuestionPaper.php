<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionPaper extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'filename',
        'academic_session_id',
        'uploader_id'
    ];
}
