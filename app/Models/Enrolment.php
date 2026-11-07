<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrolment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'user_id', 'category', 'status', 'source', 'approved_at', 'approved_by', 'note'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
