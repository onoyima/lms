<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The link a host shares with people who have no account. */
class MeetingGuestLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['meeting_id', 'token', 'passcode_hash', 'enabled', 'created_by'];

    protected $hidden = ['passcode_hash'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function url(): string
    {
        return route('meetings.guest', $this->token);
    }
}
