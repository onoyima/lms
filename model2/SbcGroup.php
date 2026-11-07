<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SbcGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'vu_semester_id',
        'members',
        'departments'
    ];

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }

}
