<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferGrading extends Model
{
    use HasFactory;

    protected $guarded = [''];

    public function transfer_grading_systems()
    {
        return $this->hasMany(TransferGradingSystem::class);
    }
}
