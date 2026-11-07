<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['scope', 'scope_id', 'title', 'body_html', 'urgent', 'publish_at', 'author_id', 'recipients'];

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'publish_at' => 'datetime'];
    }

    public function author(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
