<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcceptanceFee extends Model
{
    use HasFactory;

    protected $fillable = [

        'user_id',
        'student_id',
        'adm_year',
        'vu_session_id',
        'admissions_type_id',
        'vuna_acceptance_fee_id',
        'rrr_acceptance_fee_id',
        'amount',
        'payment_reference',
        'status_code',
        'transaction_id',
        'rrr',
        'processor_id',
        'paid_amount',
        'transaction_date',
        'payment_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function vuna_acceptance_fee()
    {
        return $this->belongsTo(VunaAcceptanceFee::class);
    }

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function rrr_acceptance_fee()
    {
        return $this->belongsTo(RrrAcceptanceFee::class);
    }

}
