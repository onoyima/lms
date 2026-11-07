<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeEntry extends Model
{
    protected $fillable = ['grade_item_id', 'user_id', 'score', 'overridden', 'updated_by'];

    protected function casts(): array
    {
        return ['overridden' => 'boolean'];
    }
}
