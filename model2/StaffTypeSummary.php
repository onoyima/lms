<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffTypeSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'staff_type_id'
    ];

}
