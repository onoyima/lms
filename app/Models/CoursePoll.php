<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePoll extends Model
{
    use BelongsToTenant;

    protected $table = 'course_polls';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'multiple' => 'boolean', 'anonymous' => 'boolean', 'closes_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
