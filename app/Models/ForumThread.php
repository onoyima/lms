<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ForumThread extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_item_id', 'user_id', 'title', 'pinned', 'locked', 'posts_count', 'last_post_at'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'locked' => 'boolean', 'last_post_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
    }

    public function posts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ForumPost::class);
    }
}
