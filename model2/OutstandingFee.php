<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutstandingFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_id',
        'tuition_fee_id',
        'amount',
        'status',
    ];

    public function tuition_fee()
    {
        return $this->belongsTo(TuitionFee::class);
    }

}
