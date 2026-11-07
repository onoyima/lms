<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TuitionFeeTopUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'student_id',
        'tuition_fee_id',
        'description',
        'amount',
        'initiated_date'
    ];
}
