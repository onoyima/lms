<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantBioData extends Model
{
    use HasFactory;


    // is_check_jamb this is to keep track if the student qualified by jamb
    // is_check_ssce this is to keep track if the student qualified by ssce
    // is_new this is to check if physical screening was done or not 1 = Done and 0 = Not Done
    // is_jamb is to distinguish jamb uploaded applicant from those that registered from the website
    // is_uploaded to check for does that where upload by the school admission unit against does that registered from the portal
    // is_ai_screening is to tell those that the recommender system approved for admission
    // is_pre_adm_screening is the recommender which can be updated by the admission committee
    // is_admitted This is the group of applicant that were offered admission
    // is_post_adm_screening This is for the screening after admission
    // is_sitting number of sittings in ssce
    // is_processed This is to check if the candicate have been processed by the Admissions Committee
    // 'is_status' 1=hostel 0=off k

    /*
        # column : page_progress
        # 1 = Processing fee page
        # 2 = level_applicant page
        # 3 = level_applicant_two first sitting page
        # 4 = level_applicant_two second sitting page
        # 5 = level_applicant_three page
        #     level_applicant_four page
        # 6 = Dashboard (Admission in process. please check back ......)
        # 7 = NIN upload (after acceptance fee)
        # 8 = Select hostel / tuition (after NIN)
        #
     */

     protected $fillable = [
            'user_id',
            'student_id',
            'verifier',
            'reg_no',
            'fname',
            'mname',
            'lname',
            'gender',
            'email',
            'phone_no',
            'password',
            'dob',
            'religion',
            'state_name',
            'state_id',
            'lga',
            'address',
            'country_id',
            'country_name',
            'page_progress',
            'course_study_id',
            'admissions_type_id',
            'vu_session_id',
            'academic_session_id',
            'vuna_scholarship_id',
            'passport_file',
            'signature',
            'nin',
            'exam_year',
            'is_new',
            'is_check_ssce',
            'is_ai_screening',
            'is_pre_adm_screening',
            'is_admitted',
            'is_post_adm_screening',
            'is_scholarship',
            'admitted_date',
            'is_sitting',
            'is_processed',
            'recommendation',
            'referral',
            'is_status',
            'status',
     ];

     public function user()
     {
         return $this->belongsTo(User::class);
     }

     public function student()
     {
         return $this->belongsTo(Student::class);
     }

     public function vu_session()
     {
         return $this->belongsTo(VuSession::class);
     }

     public function course_study()
     {
         return $this->belongsTo(CourseStudy::class);
     }

     public function admissions_type()
     {
         return $this->belongsTo(AdmissionsType::class);
     }

     public function country()
     {
         return $this->belongsTo(Country::class);
     }

     public function state()
     {
         return $this->belongsTo(State::class);
     }

     public function applicant_jamb()
    {
        return $this->hasOne(ApplicantJamb::class);
    }

    public function applicant_de()
    {
        return $this->hasOne(ApplicantDe::class);
    }

    public function applicant_transfer()
    {
        return $this->hasOne(ApplicantTransfer::class);
    }

    public function vuna_applicant_fee()
    {
        return $this->hasMany(VunaApplicantFee::class);
    }

    public function tuition_fee()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function process_applicant()
    {
        return $this->hasOne(ProcessApplicant::class, 'user_id', 'user_id');
    }

    public function applicant_sponsor()
    {
        return $this->hasOne(ApplicantSponsor::class, 'user_id', 'user_id');
    }

}
