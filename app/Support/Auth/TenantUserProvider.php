<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Loads the signed-in account (and password-reset accounts) from this institution or the
 * platform team, while ordinary queries stay limited to the current institution.
 */
class TenantUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        $query = User::forSignIn();

        return $this->queryCallback ? tap($query, $this->queryCallback) : $query;
    }
}
