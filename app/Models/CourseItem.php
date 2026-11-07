<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseItem extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['course_offering_id', 'course_section_id', 'type', 'title', 'body_html', 'file_path', 'file_name', 'file_mime', 'file_size', 'url', 'exam_id', 'prerequisite_item_id', 'release_at', 'due_at', 'cutoff_at', 'settings', 'visible', 'requires_completion', 'position', 'version', 'created_by'];

    protected function casts(): array
    {
        return ['release_at' => 'datetime', 'due_at' => 'datetime', 'cutoff_at' => 'datetime', 'settings' => 'array', 'visible' => 'boolean', 'requires_completion' => 'boolean'];
    }

    public function offering(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function section(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function exam(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function prerequisite(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'prerequisite_item_id');
    }

    public function submissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function threads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ForumThread::class);
    }
}
