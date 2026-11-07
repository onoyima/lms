<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sponsor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'applicant_bio_data_id',
        'title',
        'fname',
        'mname',
        'lname',
        'resident_state',
        'city',
        'phone_no',
        'email'
    ];
}
