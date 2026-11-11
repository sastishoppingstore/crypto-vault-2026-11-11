<?php

namespace App\Services\Security;

use RuntimeException;

/**
 * Example KMS/HSM-backed key provider (envelope encryption).
 *
 * Production pattern with AWS KMS / Google KMS / Vault:
 *
 *  1. On boot, call the KMS `Decrypt` API once with the wrapped data key
 *     stored in VAULT_WRAPPED_KEY (ciphertext blob, safe to keep on disk).
 *  2. Cache the plaintext data key in memory only (never on disk).
 *  3. Return it from key() — every EncryptedString then uses the HSM-held key,
 *     while the plaintext key never touches .env or the database.
 *
 * This class intentionally throws until wired to a real KMS client so that a
 * misconfiguration can never silently fall back to a weaker scheme.
 */
final class AwsKmsKeyProvider implements KeyProviderInterface
{
    public function key(): string
    {
        throw new RuntimeException(
            'AwsKmsKeyProvider is a template: wire it to your HSM/KMS client before enabling VAULT_KEY_PROVIDER=hsm.'
        );
    }

    public function name(): string
    {
        return 'aws-kms';
    }
}
