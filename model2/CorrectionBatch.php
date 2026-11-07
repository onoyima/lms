<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorrectionBatch extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 1;
    public const STATUS_DONE = 2;
    public const STATUS_REJECTED = 3;
    public const STATUS_CANCELLED = 4;

    protected $fillable = [
        'code',
        'staff_id',
        'department_id',
        'course_study_id',
        'level',
        'from_vu_semester_id',
        'to_vu_semester_id',
        'vu_semester_id',
        'stage',
        'status',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function courseStudy()
    {
        return $this->belongsTo(CourseStudy::class, 'course_study_id');
    }

    public function fromVuSemester()
    {
        return $this->belongsTo(VuSemester::class, 'from_vu_semester_id');
    }

    public function toVuSemester()
    {
        return $this->belongsTo(VuSemester::class, 'to_vu_semester_id');
    }

    public function vuSemester()
    {
        return $this->belongsTo(VuSemester::class, 'vu_semester_id');
    }

    public function items()
    {
        return $this->hasMany(CorrectionRequest::class, 'correction_batch_id');
    }

    public function approvals()
    {
        return $this->hasMany(CorrectionBatchApproval::class);
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }
}
