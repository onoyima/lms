<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProctoringEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'attempt_id', 'type', 'severity', 'data', 'client_time', 'created_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'client_time' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
