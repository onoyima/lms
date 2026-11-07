<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptAnswer extends Model
{
    public $timestamps = false;

    protected $fillable = ['attempt_id', 'question_id', 'response', 'client_seq', 'flagged', 'answered_at', 'score', 'marked_by', 'marked_at', 'feedback'];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'flagged' => 'boolean',
            'answered_at' => 'datetime',
            'marked_at' => 'datetime',
            'score' => 'float',
        ];
    }

    public function attempt(): BelongsTo { return $this->belongsTo(Attempt::class); }
}
