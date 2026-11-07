<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TuitionFeePg extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vuna_admission_pg_id',
        'student_id',
        'adm_year',
        'vu_session_id',
        'admissions_type_id',
        'vuna_accomodation_history_id',
        'vuna_tuition_fee_pg_id',
        'total',
        'balance',
        'payment_plan',
        'rrr_tuition_fee_pg_id',
        'amount',
        'payment_reference',
        'status_code',
        'transaction_id',
        'rrr',
        'processor_id',
        'paid_amount',
        'payment_status',
        'is_balance_unpaid',
        'transaction_time',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function vuna_admission_pg()
    {
        return $this->belongsTo(VunaAdmissionPg::class);
    }

/*     public function vuna_accomodation()
    {
        return $this->belongsTo(VunaAccomodation::class);
    } */

    public function vuna_tuition_fee_pg()
    {
        return $this->belongsTo(VunaTuitionFeePg::class);
    }

/*     public function outstanding_fee()
    {
        return $this->hasOne(OutstandingFee::class);
    } */

/*     public function vuna_accomodation_history()
    {
        return $this->hasOne(VunaAccomodationHistory::class);
    } */

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    /**
     * @return string
     */
    public function paymentMethodLabel()
    {
        if (\App\Payments\Support\BankBranchOrigin::isStamped($this)) {
            return \App\Payments\Support\BankBranchOrigin::RECEIPT_LABEL;
        }

        $rrr = (string) ($this->rrr ?? '');
        $reference = (string) ($this->payment_reference ?? '');
        if (strpos($rrr, 'PSK_') === 0 || strpos($reference, 'PSK_') === 0) {
            return 'Paystack';
        }

        return 'Remita';
    }

    /**
     * @return bool
     */
    public function isBankBranchOrigin()
    {
        return \App\Payments\Support\BankBranchOrigin::isStamped($this);
    }
}
