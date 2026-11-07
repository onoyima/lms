<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RrrApplicationFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'application_fee_id',
        'rrr',
        'status_code',
        'trans_status',
        'order_id',
    ];

    public function application_fee()
    {
        return $this->belongsTo(ApplicationFee::class);
    }

    public function vuna_applicant_fee()
    {
        return $this->hasOne(VunaApplicantFee::class);
    }

    public function vuna_applicant_fee_pg()
    {
        return $this->hasOne(VunaApplicantFeePg::class);
    }


}
