<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GradeCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'name', 'weight', 'aggregation', 'best_n', 'position'];

    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GradeItem::class);
    }
}
