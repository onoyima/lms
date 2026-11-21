<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultChange extends Model
{
    protected $fillable = ['result_id', 'from_score', 'to_score', 'reason', 'answer', 'requested_by', 'decided_by', 'status', 'decided_at'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'answer' => 'array',
            'from_score' => 'float',
            'to_score' => 'float',
        ];
    }

    public function result(): BelongsTo { return $this->belongsTo(Result::class); }
}
