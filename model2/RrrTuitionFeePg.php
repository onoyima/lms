<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RrrTuitionFeePg extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vuna_tuition_fee_pg_id',
        'amount',
        'payment_category',
        'rrr',
        'status_code',
        'trans_status',
        'order_id',
    ];

    public function vuna_tuition_fee_pg()
    {
        return $this->belongsTo(VunaTuitionFeePg::class);
    }
}
