<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['approvable_type', 'approvable_id', 'step', 'user_id', 'decision', 'comment', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
