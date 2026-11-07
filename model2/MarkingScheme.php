<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarkingScheme extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'filename',
        'question_paper_id',
        'uploader_id'
    ];
}