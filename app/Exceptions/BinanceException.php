<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Binance REST API error. $apiCode is Binance's numeric error code (if any).
 */
class BinanceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int|string|null $apiCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    /** Binance codes that are safe to retry (timestamp drift, rate limiting). */
    public static function isRetryableCode(int|string|null $code): bool
    {
        return in_array((string) $code, ['-1021', '-1003', '-1007'], true);
    }
}
