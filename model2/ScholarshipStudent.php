<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarshipStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'scholarship_body_id',
        'vu_session_id',
        'amount',
        'percentage',
        'category',
        'processed_by',
        'duration',
        'status',

    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function scholarship_body()
    {
        return $this->belongsTo(ScholarshipBody::class);
    }
    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }
    public function scholarship_histories()
    {
        return $this->hasMany(ScholarshipHistory::class);
    }

}

