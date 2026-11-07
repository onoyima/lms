<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssignedRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'staff_role_id',
        'assigner_id'
    ];
}
