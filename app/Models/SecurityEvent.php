<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'type', 'ip', 'path', 'data', 'created_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
