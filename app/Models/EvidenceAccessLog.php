<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['evidence_file_id', 'user_id', 'action', 'ip', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
