<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForumPost extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['forum_thread_id', 'user_id', 'parent_id', 'body_html', 'is_answer'];

    protected function casts(): array
    {
        return ['is_answer' => 'boolean'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'forum_thread_id');
    }
}
