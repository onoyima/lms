<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CourseGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_offering_id', 'name'];

    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_group_members');
    }
}
