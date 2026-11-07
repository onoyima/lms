<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's formal request for an exception (missed exam, deadline, registration…) and its decision (LMS 178). */
class ExceptionRequest extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'missed_exam' => 'Missed exam (illness, bereavement, clash)',
        'deadline' => 'Coursework deadline',
        'registration' => 'Late or changed course registration',
        'accessibility' => 'Accessibility arrangement',
        'hardware' => 'Exam hardware (no webcam, own device…)',
        'result' => 'Result or grade query',
        'other' => 'Something else',
    ];

    protected $fillable = ['user_id', 'type', 'course_offering_id', 'exam_id', 'subject', 'reason', 'evidence_path', 'evidence_name', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $hidden = ['evidence_path'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'reason' => 'encrypted', 'decision_note' => 'encrypted'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function offering(): BelongsTo { return $this->belongsTo(CourseOffering::class, 'course_offering_id'); }
    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
