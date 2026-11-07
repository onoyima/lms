<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'applicant_bio_data_id',
        'institution',
        'jamb_no',
        'course',
        'cgpa',
        'matric_no',
        'entry_year',
        'level',
        'weight'
    ];

    public function applicant_bio_data()
    {
        return $this->belongsTo(ApplicantBioData::class);
    }
}
