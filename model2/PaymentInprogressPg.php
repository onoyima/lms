<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentInprogressPg extends Model
{
    use HasFactory;


    protected $fillable = [
        'user_id',
        'student_id',
        'vuna_tuition_fee_pg_id',
        'payment_plan',
        'vu_session_id',
    ];

    public function vuna_tuition_fee_pg()
    {
        return $this->belongsTo(VunaTuitionFeePg::class);
    }

}
