<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RecordingChunk extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'media_recording_id', 'seq', 'disk', 'path', 'size', 'sha256', 'offset_ms'];
}
