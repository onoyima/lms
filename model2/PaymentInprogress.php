<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentInprogress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'rrr',
        'vuna_accomodation_history_id',
        'vuna_tuition_fee_id',
        'tuition_fee_id',
        'payment_plan',
        'debit_payment_plan',
        'servicing_fund',
        'initiated_date',
        'status',
        'vu_session_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vuna_tuition_fee()
    {
        return $this->belongsTo(VunaTuitionFee::class);
    }

    public function tuition_fee()
    {
        return $this->belongsTo(TuitionFee::class);
    }

}
