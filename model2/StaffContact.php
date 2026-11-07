<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'name',
        'relationship',
        'address',
        'state',
        'phone_no',
        'phone_no_two',
        'email',
    ];
}
