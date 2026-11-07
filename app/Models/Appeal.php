<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'result_id', 'incident_id', 'kind', 'statement', 'status', 'decision', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function result(): BelongsTo { return $this->belongsTo(Result::class); }
}
