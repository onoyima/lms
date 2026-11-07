<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RemCred extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_type_id',
        'account',
        'code',
        'type',
        'status',
    ];
}
