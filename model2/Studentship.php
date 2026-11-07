<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Studentship extends Model
{
    use HasFactory;

    public function studentship_histories()
    {
        return $this->hasMany(StudentshipHistory::class);
    }
}
