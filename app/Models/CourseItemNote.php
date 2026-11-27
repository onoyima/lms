<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseItemNote extends Model
{
    use BelongsToTenant;

    protected $table = 'course_item_notes';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['bookmarked' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
