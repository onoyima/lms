<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptPaper extends Model
{
    protected $primaryKey = 'attempt_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['attempt_id', 'payload', 'checksum'];
}
