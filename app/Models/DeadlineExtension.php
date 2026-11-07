<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadlineExtension extends Model
{
    protected $fillable = ['course_item_id', 'user_id', 'due_at', 'reason', 'granted_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
