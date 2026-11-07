<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = ['class_meeting_id', 'user_id', 'status', 'source'];
}
