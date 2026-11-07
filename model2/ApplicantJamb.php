<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantJamb extends Model
{
    use HasFactory;

        protected $fillable = [
        'user_id',
        'applicant_bio_data_id',
        'vuna_admission_id',
        'jamb_reg_no',
        'jamb_agg',
        'exam_year',
        'weight'
    ];

    public function applicant_bio_data()
    {
        return $this->belongsTo(ApplicantBioData::class);
    }
}
