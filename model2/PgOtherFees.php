<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PgOtherFees extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

}
