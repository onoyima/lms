<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Remita extends Model
{
    use HasFactory;


    protected $fillable = [
        'fname',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
