<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Someone who joined through a guest link: only the name they typed (and an optional e-mail). */
class MeetingGuest extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['meeting_id', 'name', 'email', 'ip', 'user_agent'];
}
