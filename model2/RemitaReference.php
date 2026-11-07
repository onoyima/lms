<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RemitaReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'rrr',
        'order_ref',
        'order_id',
        'transaction_id',
        'processor_id',
        'amount',
        'status_code',
        'payment_status',
        'transaction_time',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_time' => 'datetime',
        'raw_response' => 'array',
    ];
}
