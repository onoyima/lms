<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'fullname',
        'name',
        'role_category',
    ];

    public function students()
    {
        return $this->hasMany(Student::class);
    }

}
