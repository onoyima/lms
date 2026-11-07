<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['course_item_id', 'user_id', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
