<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScholarshipFund extends Model
{
    use HasFactory;
    protected $fillable = [
        'scholarship_body_id',
        'amount',
        'allocated',
        'balance',
        'paid_date',
    ];

    public function scholarship_body()
    {
        return $this->belongsTo(ScholarshipBody::class);
    }
}
