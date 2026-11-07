<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtraCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'student_id',
        'vu_session_id',
        'vu_semester_id',
        'old_maxload_first',
        'extra_first',
        'old_maxload_second',
        'extra_second',
    ];

}
