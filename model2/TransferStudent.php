<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'university',
        'vu_session_id',
        'transfer_grading_id',
        'level',
    ];

    public function transfer_student_results()
    {
        return $this->hasMany(TransferStudentResult::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

}
