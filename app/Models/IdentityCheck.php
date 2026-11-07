<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class IdentityCheck extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['user_id', 'attempt_id', 'kind', 'result', 'distance', 'evidence_file_id', 'created_at'];

    protected function casts(): array
    {
        return ['distance' => 'float', 'created_at' => 'datetime'];
    }
}
