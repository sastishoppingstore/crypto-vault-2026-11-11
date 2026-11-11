<?php

namespace App\Services\Security;

/**
 * Source of the 32-byte AES-256 key used for vault encryption.
 *
 * Implementations:
 *  - EnvKeyProvider     : key from VAULT_MASTER_KEY (default)
 *  - AwsKmsKeyProvider  : envelope encryption with an external KMS/HSM
 */
interface KeyProviderInterface
{
    /** Raw 32-byte key material. */
    public function key(): string;

    /** Short identifier of the active key (used for rotation detection). */
    public function name(): string;
}
