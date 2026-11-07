<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgrammeCertification extends Model
{
    use HasFactory;

    protected $fillable = [
        'name'
    ];

    public function program_offers()
    {
        return $this->hasMany(ProgramOffered::class);
    }
}
