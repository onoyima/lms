<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['attempt_id', 'sender_id', 'direction', 'kind', 'body', 'acknowledged_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
