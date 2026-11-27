<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'topic_requests';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime', 'votes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
