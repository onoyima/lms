<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
    ];

    public function applicant_upload_files()
    {
        return $this->hasMany(ApplicantUploadFile::class);
    }

}
