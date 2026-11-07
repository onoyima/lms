<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatGen extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'status'
    ];
}
