<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduledEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_type_id',
        'starttime',
        'endtime'
    ];
}
