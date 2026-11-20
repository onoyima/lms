<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['attempt_id', 'sender_id', 'direction', 'kind', 'body', 'acknowledged_at', 'created_at'];

    protected static function booted(): void
    {
        // the exam room only looks for messages when this flag says there may be some
        static::created(function (self $m) {
            if ($m->direction === 'to_candidate') {
                \Illuminate\Support\Facades\Cache::put(\App\Services\Exams\AttemptService::messageFlag((int) $m->attempt_id), 1, now()->addHours(12));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
