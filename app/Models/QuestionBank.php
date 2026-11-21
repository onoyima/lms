<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named pool of questions that belongs to one exam, to one exam category (all its papers), or to
 * a course. A course or exam can have many banks, for example one per topic. When a paper is put
 * together, questions come from one bank, several, or a random draw with a minimum from each.
 */
class QuestionBank extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'topic', 'description', 'exam_id', 'exam_group_id', 'course_id', 'created_by', 'centre_ref'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ExamGroup::class, 'exam_group_id');
    }

    /** The banks an exam may use: its own, its category's, and its course's. */
    public function scopeForExam(Builder $q, Exam $exam): Builder
    {
        return $q->where(function ($w) use ($exam) {
            $w->where('exam_id', $exam->id);
            $exam->exam_group_id && $w->orWhere('exam_group_id', $exam->exam_group_id);
            $exam->course_id && $w->orWhere(fn ($c) => $c->where('course_id', $exam->course_id)->whereNull('exam_id')->whereNull('exam_group_id'));
        });
    }

    /** "This exam", "All papers in the category" or "Course" — where the bank lives, in words. */
    public function scopeLabel(): string
    {
        return $this->exam_id ? 'This paper' : ($this->exam_group_id ? 'Whole exam category' : 'Course');
    }
}
