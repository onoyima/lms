<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'subject', 'category', 'priority', 'status', 'assigned_to', 'course_offering_id', 'last_activity_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
