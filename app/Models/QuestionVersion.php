<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['question_id', 'version', 'snapshot', 'created_at'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}
