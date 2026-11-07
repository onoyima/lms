<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'country_id'
    ];

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }

    public function student()
    {
        return $this->hasMany(Student::class);
    }

    public function applicant_bio_datas()
    {
        return $this->hasMany(ApplicantBioData::class);
    }

    public function vuna_admission_pgs()
    {
        return $this->hasMany(VunaAdmissionPg::class);
    }

    public function vuna_admission_jupeps()
    {
        return $this->hasMany(VunaAdmissionJupep::class);
    }

    public function vuna_admission_sandwichs()
    {
        return $this->hasMany(VunaAdmissionSandwich::class);
    }
}
