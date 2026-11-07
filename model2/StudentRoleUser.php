<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentRoleUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_role_id',
    ];
}
