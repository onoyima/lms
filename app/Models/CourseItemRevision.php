<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseItemRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['course_item_id', 'version', 'snapshot', 'edited_by', 'created_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
