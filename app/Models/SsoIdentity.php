<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsoIdentity extends Model
{
    protected $fillable = ['sso_provider_id', 'user_id', 'subject', 'last_login_at'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }
}
