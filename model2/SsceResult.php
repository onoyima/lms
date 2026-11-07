<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SsceResult extends Model
{
    use HasFactory;

    protected $fillable = [

        'ssce_meta_result_id',
        'secondary_subject_id',
        'secondary_grade_id',
    ];

    public function ssce_meta_result()
    {
        return $this->belongsTo(SsceMetaResult::class);
    }

    public function secondary_grade()
    {
        return $this->belongsTo(SecondaryGrade::class);
    }

    public function secondary_subject()
    {
        return $this->belongsTo(SecondarySubject::class);
    }

}
