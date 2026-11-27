<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePollVote extends Model
{
    use BelongsToTenant;

    protected $table = 'course_poll_votes';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['choices' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
