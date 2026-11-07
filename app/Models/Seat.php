<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Seat extends Model
{
    public $timestamps = false;

    protected $fillable = ['venue_id', 'label', 'device_id'];

    public function venue(): BelongsTo { return $this->belongsTo(Venue::class); }
}
