<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptQuestion extends Model
{
    public $timestamps = false;

    protected $fillable = ['attempt_id', 'question_id', 'question_version', 'exam_section_id', 'position', 'mark', 'option_order', 'key'];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return [
            'option_order' => 'array',
            'key' => 'array',
            'mark' => 'float',
        ];
    }

    public function question(): BelongsTo { return $this->belongsTo(Question::class); }
}
