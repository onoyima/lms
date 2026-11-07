<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantSponsor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'applicant_bio_data_id',
        'title',
        'name',
        'relationship',
        'phone_no',
        'email',
        'address',
        'occupation',
    ];

    public function applicant_bio_data()
    {
        return $this->belongsTo(ApplicantBioData::class);
    }

}
