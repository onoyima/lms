<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One stretch of time a person was in a meeting, rebuilt from BigBlueButton's join and leave events. */
class MeetingAttendance extends Model
{
    use BelongsToTenant;

    protected $table = 'meeting_attendance';

    protected $fillable = ['meeting_id', 'user_id', 'internal_user_id', 'name', 'role', 'joined_at', 'left_at', 'seconds', 'closed_by_meeting_end'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime', 'closed_by_meeting_end' => 'boolean', 'seconds' => 'integer'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
