<?php

namespace App\Services\Blockchain;

/**
 * Result of an HD derivation. Private key material lives only in memory;
 * it is never persisted by the application.
 */
final readonly class DerivedKey
{
    public function __construct(
        public string $address,
        public string $path,
        public string $privateKeyHex,
        public string $publicKeyHex,
    ) {
    }
}
