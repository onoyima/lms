<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RrrTuitionFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'vuna_tuition_fee_id',
        'payment_category',
        'amount',
        'rrr',
        'status_code',
        'trans_status',
        'order_id',
        'status',
    ];

    // status: 0 = generated, 1 = processed payment, 2 = deleted RRR

    public function student(){
        return $this->belongsTo(Student::class);
    }

}


