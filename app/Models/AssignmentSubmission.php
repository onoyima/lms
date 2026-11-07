<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AssignmentSubmission extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_item_id', 'user_id', 'course_group_id', 'attempt', 'text', 'file_path', 'file_name', 'file_mime', 'file_size', 'file_hash', 'anon_code', 'submitted_at', 'is_late', 'status', 'score', 'raw_score', 'rubric_scores', 'feedback_html', 'marked_by', 'marked_at', 'released'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'marked_at' => 'datetime', 'is_late' => 'boolean', 'released' => 'boolean', 'rubric_scores' => 'array', 'similarity_matches' => 'array', 'similarity_checked_at' => 'datetime'];
    }

    public function item(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourseItem::class, 'course_item_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
