<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An exam category (e.g. Nursing professional exam, Semester exam, Departmental exam, GST): a set of
 * separate exams (objective, theory, practical, viva, tests, quizzes), each with its own questions,
 * students, examiners, time and proctoring. Students can be assigned to one exam, or to the whole
 * category (then they sit every exam in it, including exams added later).
 */
class ExamGroup extends Model
{
    use BelongsToTenant;

    /** The kinds of paper, in the order they are usually sat, with the name people use for them. */
    public const PAPERS = [
        'cbt' => 'Objective',
        'essay' => 'Theory',
        'mixed' => 'Objective and theory',
        'practical' => 'Practical',
        'viva' => 'Viva',
        'research' => 'Project defence',
    ];

    protected $fillable = ['title', 'code', 'description', 'course_id', 'semester_id', 'created_by'];

    public function papers(): HasMany { return $this->hasMany(Exam::class)->orderByRaw("CASE type WHEN 'cbt' THEN 1 WHEN 'mixed' THEN 2 WHEN 'essay' THEN 3 WHEN 'practical' THEN 4 WHEN 'viva' THEN 5 ELSE 6 END")->orderBy('id'); }
    /** Students assigned to the whole category: they sit every exam in it. */
    public function students(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'exam_group_students')->withTimestamps()->withPivot('tenant_id');
    }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function semester(): BelongsTo { return $this->belongsTo(Semester::class); }
}
