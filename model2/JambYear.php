<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JambYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
    ];
}
