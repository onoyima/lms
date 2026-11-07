<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'admissions_type_id',
        'description',
        'amount',
    ];

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function vuna_applicant_fee()
    {
        return $this->hasMany(VunaApplicantFee::class);
    }

    public function vuna_applicant_fee_pg()
    {
        return $this->hasMany(VunaApplicantFeePg::class);
    }

    public function rrr_application_fees()
    {
        return $this->hasMany(RrrApplicationFee::class);
    }

}
