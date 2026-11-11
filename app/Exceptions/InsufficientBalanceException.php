<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $asset = '',
        public readonly string $available = '0',
        public readonly string $required = '0',
    ) {
        parent::__construct("Insufficient {$asset} balance. Available {$available}, required {$required}.");
    }
}
