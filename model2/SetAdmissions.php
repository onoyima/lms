<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SetAdmissions extends Model
{
    use HasFactory;

    protected $fillable = [
        'adm_year',
        'academic_session_id',
        'vu_session_id',
        'admissions_type_id',
        'is_publish',
        'start_date',
        'end_date',
    ];

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);
    }
    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

}
