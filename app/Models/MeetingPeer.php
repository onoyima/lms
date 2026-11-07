<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One browser in a built-in meeting room. */
class MeetingPeer extends Model
{
    use BelongsToTenant;

    public const MODERATOR_ROLES = ['host', 'moderator'];

    protected $fillable = ['meeting_id', 'peer_id', 'user_id', 'meeting_guest_id', 'name', 'role', 'state', 'audio', 'video', 'hand', 'screen', 'joined_at', 'last_seen_at', 'left_at'];

    protected function casts(): array
    {
        return ['audio' => 'boolean', 'video' => 'boolean', 'hand' => 'boolean', 'screen' => 'boolean', 'joined_at' => 'datetime', 'last_seen_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function moderates(): bool
    {
        return in_array($this->role, self::MODERATOR_ROLES, true);
    }

    public function card(): array
    {
        return ['peer_id' => $this->peer_id, 'name' => $this->name, 'role' => $this->role, 'audio' => $this->audio, 'video' => $this->video, 'hand' => $this->hand, 'screen' => $this->screen, 'guest' => $this->meeting_guest_id !== null];
    }
}
