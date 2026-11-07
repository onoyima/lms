<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PgAdmRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'admissions_type_id',
        'name',
    ];

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function applicant_pg_education()
    {
        return $this->hasMany(ApplicantPgEducation::class);
    }
}
