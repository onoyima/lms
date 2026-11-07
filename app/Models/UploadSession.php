<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UploadSession extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['user_id', 'purpose', 'filename', 'mime', 'size', 'received', 'path', 'status', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'size' => 'integer', 'received' => 'integer'];
    }
}
