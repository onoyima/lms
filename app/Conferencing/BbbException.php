<?php

namespace App\Conferencing;

use RuntimeException;

/** BigBlueButton could not be reached or refused the call. The message is safe to show. */
class BbbException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $messageKey = null)
    {
        parent::__construct($message);
    }
}
