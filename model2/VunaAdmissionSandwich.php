<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VunaAdmissionSandwich extends Model
{

     // is_check_jamb this is to keep track if the student qualified by jamb
    // is_check_ssce this is to keep track if the student qualified by ssce
    // is_new
    // is_jamb is to distinguish jamb uploaded applicant from those that registered from the website
    // is_uploaded to check for does that where upload by the school admission unit against does that registered from the portal
    // is_ai_screening is to tell those that the recommender system approved for admission
    // is_pre_adm_screening is the recommender which can be updated by the admission committee
    // is_admitted This is the group of applicant that were offered admission
    // is_post_adm_screening This is for the screening after admission
    // is_sitting number of sittings in ssce
    // is_processed This is to check if the candicate have been processed by the Admissions Committee

    /*
        # column : page_progress
        # 1 = Processing fee page
        # 2 = level_applicant page
        # 3 = level_applicant_two first sitting page
        # 4 = level_applicant_two second sitting page
        # 5 = level_applicant_three page
        #     level_applicant_four page
        # 6 = Dashboard (Admission in process. please check back ......)
        #
        #
     */

     use HasFactory;

     protected $fillable = [

         'user_id',
         'set_admission_id',
         'reg_no',
         'verifier',
         'fname',
         'mname',
         'lname',
         'gender',
         'state',
         'dob',
         'a_level',
         'country_id',
         'agg',
         'course',
         'weight',
         'page_progress',
         'admissions_type_id',
         'course_study_id',
         'vu_session_id',
         'academic_session_id',
         'vuna_scholarship_id',
         'passport_file',
         'phone_no',
         'email',
         'password',
         'application_type',
         'exam_year',
         'is_new',
         'is_jamb',
         'is_uploaded',
         'is_check_jamb',
         'is_check_ssce',
         'is_ai_screening',
         'is_pre_adm_screening',
         'is_admitted',
         'is_post_adm_screening',
         'is_scholarship',
         'admitted_date',
         'is_sitting',
         'is_processed',

     ];

     public function user()
    {
        return $this->belongsTo(User::class);
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

}
