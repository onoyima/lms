<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferStudentResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_student_id',
        'student_id',
        'code',
        'title',
        'credit_load',
        'score',
        'semester',
    ];

    public function transfer_student()
    {
        return $this->belongsTo(TransferStudent::class);
    }
}
