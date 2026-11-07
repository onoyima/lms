<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantPgEducation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vuna_admission_pg_id',
        'institution',
        'course',
        'period',
        'date_awarded',
        'cgpa',
        'certificate_type',
        'pg_adm_requirement_id',
    ];

    public function pg_adm_requirement()
    {
        return $this->belongsTo(PgAdmRequirement::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
