<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessApplicant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'staff_id',
        'category',
        'status',
    ];

    public function applicant_bio_data()
    {
        return $this->belongsTo(ApplicantBioData::class, 'user_id','user_id');
    }
}
