<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarshipHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'scholarship_student_id',
        'staff_id',
        'amount',
        'status',
    ];

    public function scholarship_student()
    {
        return $this->belongsTo(ScholarshipStudent::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
