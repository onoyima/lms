<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostAdmRequirementFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'post_adm_requirement_id',
        'filename'
    ];

    public function post_adm_requirement()
    {
        return $this->belongsTo(PostAdmRequirement::class);
    }
}
