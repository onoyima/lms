<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one computer reported, with its IP: the result of a computer check, or problems during an exam. */
class ComputerReport extends Model
{
    use BelongsToTenant;

    protected $fillable = ['computer_check_id', 'exam_session_id', 'attempt_id', 'kind', 'ip', 'label', 'user_agent', 'status', 'results', 'errors', 'error_count', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['results' => 'array', 'errors' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(ComputerCheck::class, 'computer_check_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }
}
