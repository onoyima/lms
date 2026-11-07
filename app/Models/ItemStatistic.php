<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemStatistic extends Model
{
    public $timestamps = false;

    protected $fillable = ['exam_id', 'question_id', 'responses', 'difficulty', 'discrimination', 'point_biserial', 'option_counts', 'computed_at'];

    protected function casts(): array
    {
        return [
            'option_counts' => 'array',
            'computed_at' => 'datetime',
            'difficulty' => 'float',
            'discrimination' => 'float',
            'point_biserial' => 'float',
        ];
    }
}
