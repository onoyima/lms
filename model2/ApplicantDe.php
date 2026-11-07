<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantDe extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'applicant_bio_data_id',
        'jamb_de_no',
        'weight',
        'qualification',
        'qualification_number',
        'qualification_year',
        'course_applied',
    ];

    public function applicant_bio_data()
    {
        return $this->belongsTo(ApplicantBioData::class);
    }
}
