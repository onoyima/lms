<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VunaTuitionFeePg extends Model
{
    use HasFactory;

    protected $fillable = [
        'faculty_id',
        'vu_session_id',
        'admissions_type_id',
        'name',
        'description',
        'fee',
        'others',
    ];


    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function payment_inprogress_pg()
    {
        //return $this->hasOne(model, foreign_id, primary_id);
        return $this->hasOne(PaymentInprogressPg::class);
    }

    public function rrr_tuition_fee_pg()
    {
        return $this->hasMany(RrrTuitionFeePg::class);
    }

    public function tuition_fee_pg()
    {
        return $this->hasMany(TuitionFeePg::class);
    }

}
