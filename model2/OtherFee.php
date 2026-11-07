<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtherFee extends Model
{
    use HasFactory;
    protected $fillable = [
        'description',
        'amount',
        'code', // Paystack subaccount code (ACCT_xxx)
    ];

    public function other_fee_trans()
    {
        return $this->hasMany(OtherFeeTran::class);
    }
}
