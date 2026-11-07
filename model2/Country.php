<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'phonecode',
    ];

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }

    public function student()
    {
        return $this->hasMany(Student::class);
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


