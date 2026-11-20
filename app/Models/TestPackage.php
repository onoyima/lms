<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A package of practice or mock exams sold in the test-series shop. */
class TestPackage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['title', 'description', 'price', 'currency', 'access_days', 'active', 'position', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price' => 'integer'];
    }

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'test_package_exams');
    }
}
