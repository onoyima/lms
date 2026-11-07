<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SsceMetaResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_type',
        'exam_year',
        'exam_number',
        'pin_number',
        'serial_number',
    ];

    public function ssce_results()
    {
        return $this->hasMany(SsceResult::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
