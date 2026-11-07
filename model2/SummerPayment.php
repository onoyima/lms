<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummerPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'vu_session_id',
        'academic_session_id',
        'units',
        'balance',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
