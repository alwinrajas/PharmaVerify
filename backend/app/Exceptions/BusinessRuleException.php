<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when an operation is refused by a business rule rather than by a
 * technical fault. The message is written for the end user and is safe to
 * show as-is.
 */
class BusinessRuleException extends Exception
{
    public function __construct(string $message, private readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
