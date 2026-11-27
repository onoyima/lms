<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseReview extends Model
{
    use BelongsToTenant;

    protected $table = 'course_reviews';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'anonymous' => 'boolean', 'hidden' => 'boolean', 'replied_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
