<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOffering extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_id', 'semester_id', 'capacity', 'status', 'review_status', 'copied_from_id', 'outline_html'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_offering_staff')->withPivot('role')->withTimestamps();
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourseItem::class)->orderBy('position');
    }

    public function gradeCategories(): HasMany
    {
        return $this->hasMany(GradeCategory::class)->orderBy('position');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(CourseGroup::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(ClassMeeting::class)->orderByDesc('held_at');
    }

    /** "CHM 101 · 2026/2027 First semester" */
    /** "2025/2026 First semester" (without the course code, for places that already show it). */
    public function semesterLabel(): string
    {
        $this->loadMissing('semester.session');

        return trim(($this->semester?->session?->name ?? '').' '.($this->semester?->name ?? ''));
    }

    public function label(): string
    {
        $this->loadMissing('course', 'semester.session');

        return trim($this->course->code.' · '.($this->semester?->session?->name ?? '').' '.($this->semester?->name ?? ''));
    }
}
