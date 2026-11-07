<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantUploadFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'applicant_upload_id',
        'filename',
        'status',
    ];

    public function applicant_upload()
    {
        return $this->belongsTo(ApplicantUpload::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
