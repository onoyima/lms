<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's report, from the exam screen, that a question is faulty. */
class QuestionReport extends Model
{
    use BelongsToTenant;

    public const REASONS = [
        'wrong_key' => 'The marked answer looks wrong',
        'two_answers' => 'More than one option is right',
        'no_answer' => 'None of the options is right',
        'unclear' => 'Typing error or unclear wording',
        'missing_picture' => 'A picture or equation does not show',
        'other' => 'Something else',
    ];

    protected $fillable = ['exam_id', 'attempt_id', 'question_id', 'user_id', 'reason', 'note', 'status', 'resolution', 'response', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }
}
