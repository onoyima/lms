<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RrrOtherFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'other_fee_history_id',
        'rrr',
        'amount',
        'description',
        'status_code',
        'order_id',
        'status',
    ];

    // status: 0 = generated, 1 = processed payment, 2 = deleted RRR

    public function student(){
        return $this->belongsTo(Student::class);
    }

    public function other_fee_tran()
    {
        return $this->hasOne(OtherFeeTran::class);
    }

    public function other_fee_history()
    {
        return $this->belongsTo(OtherFeeHistory::class);
    }

}
