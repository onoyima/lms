<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MeetingParticipant extends Model
{
    use BelongsToTenant;

    protected $fillable = ['meeting_id', 'user_id', 'role', 'added_by'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
