<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantPgSponsor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vuna_applicant_pg_id',
        'name',
        'relationship',
        'phone_no',
        'email',
        'address',
        'occupation',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
