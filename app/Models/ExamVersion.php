<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['exam_id', 'version', 'snapshot', 'checksum', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}
