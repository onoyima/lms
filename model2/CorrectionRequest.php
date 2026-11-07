<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorrectionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'correction_batch_id',
        'student_id',
        'staff_id',
        'course_reg_id',
        'course_id',
        'staff_type',
        'type',
        'scope',
        'level',
        'subject',
        'description',
        'old_ca_one',
        'old_ca_two',
        'old_ca_three',
        'old_examination',
        'new_ca_one',
        'new_ca_two',
        'new_ca_three',
        'new_examination',
        'payload',
        'approval_path',
        'academic_session_id',
        'vu_semester_id',
        'course_study_id',
        'department_id',
        'stage',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(CorrectionBatch::class, 'correction_batch_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function course_reg()
    {
        return $this->belongsTo(CourseReg::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function academic_session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class);
    }

    public function courseStudy()
    {
        return $this->belongsTo(CourseStudy::class, 'course_study_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function approvals()
    {
        return $this->hasMany(CorrectionRequestApproval::class);
    }

    public function isReadyForBatch(): bool
    {
        return $this->stage === 'ready'
            && (int) $this->status === 1
            && empty($this->correction_batch_id);
    }
}
