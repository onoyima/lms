<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostAdmRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'admissions_type_id',
        'name',
    ];

    public function post_adm_requirement_files()
    {
        return $this->hasMany(PostAdmRequirementFile::class);
    }

    public function admissions_type()
    {
        return $this->belongsTo(AdmissionsType::class);

    }

}
