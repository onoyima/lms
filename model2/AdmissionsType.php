<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdmissionsType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'programme_id',
    ];

    public function programme()
    {
        return $this->belongsTo(Programme::class);
    }

    public function post_adm_requirement()
    {
        return $this->hasMany(PostAdmRequirement::class);
    }

    public function pg_adm_requirement()
    {
        return $this->hasMany(PgAdmRequirement::class);
    }

    public function application_fee()
    {
        return $this->hasOne(ApplicationFee::class);
    }

    public function set_admissions()
    {
        return $this->hasMany(SetAdmissions::class);
    }

    public function applicant_bio_datas()
    {
        return $this->hasMany(ApplicantBioData::class);
    }

    public function vuna_admission_pgs()
    {
        return $this->hasMany(VunaAdmissionPg::class);
    }

    public function vuna_admissions_jupeps()
    {
        return $this->hasMany(VunaAdmissionJupep::class);
    }

    public function vuna_admissions_sandwichs()
    {
        return $this->hasMany(VunaAdmissionSandwich::class);
    }

    public function vuna_acceptance_fee()
    {
        return $this->hasOne(VunaAcceptanceFee::class);
    }

    public function acceptance_fees()
    {
        return $this->hasMany(AcceptanceFee::class);
    }

    public function vuna_applicant_fees()
    {
        return $this->hasMany(VunaApplicantFee::class);
    }

    public function vuna_applicant_fee_pgs()
    {
        return $this->hasMany(VunaApplicantFeePg::class);
    }

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function tuition_fee_pgs()
    {
        return $this->hasMany(TuitionFeePg::class);
    }

}
