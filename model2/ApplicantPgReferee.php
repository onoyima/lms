<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ApplicantPgReferee extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vuna_admission_pg_id',
        'name',
        'position',
        'institution',
        'email',
        'relationship',
        'address',
        'letter',
        'verifier',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function admission()
    {
        return $this->belongsTo(VunaAdmissionPg::class, 'vuna_admission_pg_id');
    }
}
