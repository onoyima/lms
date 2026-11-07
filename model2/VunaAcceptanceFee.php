<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VunaAcceptanceFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'admissions_type_id',
        'fee',
    ];

    public function acceptance_fees()
    {
        return $this->hasMany(AcceptanceFee::class);
    }

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }

    public function rrr_acceptance_fees()
    {
        return $this->hasMany(RrrAcceptanceFee::class);
    }
}
