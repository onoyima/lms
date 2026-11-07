<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SsoProvider extends Model
{
    use BelongsToTenant;

    protected $fillable = ['type', 'name', 'slug', 'config', 'enabled', 'auto_create', 'default_user_type', 'default_role', 'link_by_email', 'trust_idp_mfa', 'role_rules', 'type_attribute'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'enabled' => 'boolean', 'auto_create' => 'boolean', 'link_by_email' => 'boolean', 'trust_idp_mfa' => 'boolean', 'role_rules' => 'array'];
    }
}
