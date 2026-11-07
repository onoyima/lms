<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RrrAcceptanceFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vuna_acceptance_fee_id',
        'rrr',
        'status_code',
        'trans_status',
        'order_id',
    ];

    public function vuna_acceptance_fee()
    {
        return $this->belongsTo(VunaAcceptanceFee::class);
    }

    public function acceptance_fee()
    {
        return $this->hasOne(AcceptanceFee::class);
    }
}
