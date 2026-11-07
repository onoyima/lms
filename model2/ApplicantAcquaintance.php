<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantAcquaintance extends Model
{
    use HasFactory;

    protected $fillable = [

        'user_id',
        'school_year',
        'jamb_count',
        'sponsor',
        'academic',
        'admitted',
        'apprehended',
        'cultism',
        'drugs_check',
        'health_challenge',
        'physical_challenge'

    ];
}
