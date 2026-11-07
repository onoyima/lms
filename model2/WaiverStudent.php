<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaiverStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'staff_id',
        'vu_session_id',
        'amount',
        'percentage',
        'category',
        'duration',
        'message',
        'processed_by',
        'status'
    ];

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

}
