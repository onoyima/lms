<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceFile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['attempt_id', 'incident_id', 'kind', 'disk', 'path', 'mime', 'size', 'sha256', 'encrypted', 'captured_at', 'delete_after'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
            'captured_at' => 'datetime',
            'delete_after' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo { return $this->belongsTo(Attempt::class); }
    public function accessLogs(): HasMany { return $this->hasMany(EvidenceAccessLog::class); }
}
