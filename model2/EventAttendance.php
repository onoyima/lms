<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        
        'student_id',
        'academic_session_id',
        'payment_type_id',
        'transaction_code'

    ];

}
