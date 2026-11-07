<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['subject', 'created_by', 'course_offering_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }
}
