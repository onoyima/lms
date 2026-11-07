<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundingHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'student_id',
        'user_id',
        'description',
        'amount',
        'prev_amount',
        'prev_outstanding',
        'category',
        'status',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
