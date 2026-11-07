<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecondarySubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject',
    ];

    public function requirement_two()
    {
        return $this->hasMany(RequirementTwo::class);
    }

    public function ssce_result()
    {
        return $this->hasOne(SsceResult::class);
    }
}
