<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * On the main server: an exam centre that runs sittings without internet. On a centre's own
 * server: the main server it takes packages from (role "main"). Both sides share the signing key.
 */
class ExamCentre extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'code', 'secret', 'role', 'notes', 'last_package_at', 'last_results_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'last_package_at' => 'datetime', 'last_results_at' => 'datetime'];
    }
}
