<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MediaRecording extends Model
{
    use BelongsToTenant;

    protected $fillable = ['attempt_id', 'kind', 'egress_id', 'status', 'location', 'size', 'duration_seconds', 'error', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
