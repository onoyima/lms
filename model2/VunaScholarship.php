<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VunaScholarship extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'status',
    ];

    public function scholarship_students()
    {
        return $this->hasMany(ScholarshipStudent::class);
    }

}
