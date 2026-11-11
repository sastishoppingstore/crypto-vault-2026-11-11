<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a withdrawal cannot be processed.
 * $retryable = true means the queue job should retry (transient network/5xx);
 * false means the withdrawal must be failed and funds unlocked immediately.
 */
class WithdrawalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, false, $previous);
    }

    public static function transient(string $message, ?Throwable $previous = null): self
    {
        return new self($message, true, $previous);
    }
}
