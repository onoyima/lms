<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtherFeeTran extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'department_id',
        'description',
        'vu_session_id',
        'other_fee_id',
        'rrr_other_fee_id',
        'other_fee_history_id',
        'amount',
        'payment_reference',
        'status_code',
        'transaction_id',
        'rrr',
        'processor_id',
        'payment_status',
        'transaction_date',
        'level',
    ];

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function other_fee()
    {
        return $this->belongsTo(OtherFee::class);
    }

    public function rrr_other_fee()
    {
        return $this->belongsTo(RrrOtherFee::class);
    }

    public function other_fee_history()
    {
        return $this->belongsTo(OtherFeeHistory::class);
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
