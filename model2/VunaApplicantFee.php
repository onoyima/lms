<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VunaApplicantFee extends Model
{
    use HasFactory;

    protected $fillable = [
            'user_id',
            'student_id',
            'applicant_bio_data_id',
            'adm_year',
            'vu_session_id',
            'admissions_type_id',
            'application_fee_id',
            'rrr_application_fee_id',
            'amount',
            'payment_reference',
            'status_code',
            'transaction_id',
            'rrr',
            'processor_id',
            'paid_amount',
            'payment_status',
            'transaction_date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function applicant_bio_data()
    {
        return $this->belongsTo(ApplicantBioData::class);
    }

    public function application_fee()
    {
        return $this->belongsTo(ApplicationFee::class);
    }

    public function rrr_application_fee()
    {
        return $this->belongsTo(RrrApplicationFee::class);
    }

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }
}
