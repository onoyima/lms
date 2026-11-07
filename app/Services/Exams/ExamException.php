<?php

namespace App\Services\Exams;

use RuntimeException;

/** A refusal the candidate should see in plain words (wrong time, no PIN, closed attempt...). */
class ExamException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'refused', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->status);
    }
}
