<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorrectionBatchApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'correction_batch_id',
        'staff_id',
        'department_id',
        'course_study_id',
        'level',
        'vu_semester_id',
        'type',
        'comment',
        'approved_date',
    ];

    protected $casts = [
        'approved_date' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(CorrectionBatch::class, 'correction_batch_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
