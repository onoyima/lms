<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorrectionRequestApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'correction_request_id',
        'type',
        'approved_date'
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

}
