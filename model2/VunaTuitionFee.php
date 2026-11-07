<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VunaTuitionFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_study_id',
        'vu_session_id',
        'fee',
        'fee_new',
        'others',
    ];

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function payment_inprogresses()
    {
        return $this->hasMany(PaymentInprogress::class);
    }

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function tuition_fee_pgs()
    {
        return $this->hasMany(TuitionFeePg::class);
    }

}
