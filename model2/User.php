<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_ADMIN = 1;
    const ROLE_STUDENT = 2;
    const ROLE_APPLICANT = 7;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fname',
        'mname',
        'lname',
        'name',
        'email',
        'user_type',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function staff()
    {

        return $this->hasOne(Staff::class);
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function applicant_bio_data()
    {
        //return $this->hasOne(model, foreign_id, primary_id);
        return $this->hasOne(ApplicantBioData::class);
    }

    public function vuna_admission_pg()
    {
        return $this->hasOne(VunaAdmissionPg::class);
    }

    public function vuna_admission_jupep()
    {
        return $this->hasOne(VunaAdmissionJupep::class);
    }

    public function vuna_admission_sandwich()
    {
        return $this->hasOne(VunaAdmissionSandwich::class);
    }

    public function scholarship_students()
    {
        return $this->hasMany(ScholarshipStudent::class);
    }

    public function applicant_upload_files()
    {
        return $this->hasMany(ApplicantUploadFile::class);
    }

    public function ssce_meta_results()
    {
        return $this->hasMany(SsceMetaResult::class);
    }

    /* public function initiate_payment()
    {
        return $this->hasOne(InitiatePayment::class);
    } */

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function applicant_pg_referees()
    {
        return $this->hasMany(ApplicantPgReferee::class);
    }

    public function applicant_pg_educations()
    {
        return $this->hasMany(ApplicantPgEducation::class);
    }

    public function applicant_pg_sponsor()
    {
        return $this->hasOne(ApplicantPgSponsor::class);
    }

    public function tuition_fee_pgs()
    {
        return $this->hasMany(TuitionFeePg::class);
    }

    public function vuna_applicant_fee()
    {
        return $this->hasOne(VunaApplicantFee::class);
    }

    public function vuna_applicant_fee_pg()
    {
        return $this->hasOne(VunaApplicantFeePg::class);
    }

    public function payment_inprogress()
    {
        return $this->hasOne(PaymentInprogress::class);
    }

    public function acceptance_fee()
    {
        return $this->hasOne(AcceptanceFee::class);
    }

    public function userType()
    {

        if($this->user_type == self::ROLE_ADMIN)
        {
            return true;
        }
        if($this->user_type == self::ROLE_STUDENT)
        {
            return true;
        }
        if($this->user_type == self::ROLE_APPLICANT)
        {
            return true;
        }
        return false;
    }
}
