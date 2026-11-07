<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutstandingHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'staff_id',
        'description',
        'new_amount',
        'old_amount',
        'initiated_date',
    ];

}
