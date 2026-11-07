<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A BigBlueButton recording. Its playback address stays on the server and is only reached through a signed, short-lived link. */
class MeetingRecording extends Model
{
    use BelongsToTenant;

    protected $fillable = ['meeting_id', 'record_id', 'state', 'format', 'playback_url', 'duration_seconds', 'size', 'participants', 'started_at', 'ended_at', 'visibility'];

    protected $hidden = ['playback_url'];

    protected function casts(): array
    {
        return ['playback_url' => 'encrypted', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function meeting(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
