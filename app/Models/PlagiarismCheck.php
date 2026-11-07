<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A submission sent to the institution's plagiarism service and the score it returned (LMS 222). */
class PlagiarismCheck extends Model
{
    use BelongsToTenant;

    protected $fillable = ['assignment_submission_id', 'provider', 'token', 'external_id', 'status', 'score', 'report_url', 'error', 'requested_by'];

    protected function casts(): array
    {
        return ['score' => 'float'];
    }

    public function submission(): BelongsTo { return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id'); }
}
