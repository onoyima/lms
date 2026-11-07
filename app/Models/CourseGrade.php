<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CourseGrade extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'user_id', 'total', 'grade', 'breakdown', 'status', 'released_at'];

    protected function casts(): array
    {
        return ['breakdown' => 'array', 'released_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
