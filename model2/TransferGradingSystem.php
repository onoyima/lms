<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferGradingSystem extends Model
{
    use HasFactory;

    protected $guarded = [''];

    public function transfer_grading()
    {
        return $this->belongsTo(TransferGrading::class);
    }
}
