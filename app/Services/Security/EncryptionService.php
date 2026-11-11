<?php

namespace App\Services\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * AES-256-GCM envelope encryption for all sensitive vault data
 * (HD seed, hot wallet keys, exchange API keys, 2FA secrets).
 *
 * Ciphertext format:  v1:{keyId}:{base64( nonce(12) | tag(16) | ciphertext )}
 *
 *  - keyId lets the system detect data encrypted with a rotated-out key
 *    (see `php artisan vault:reencrypt`).
 *  - GCM gives confidentiality + integrity (tampering fails authentication).
 *  - The AAD binds the ciphertext to its key version.
 */
final class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 'v1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private string $key;
    private string $keyId;

    public function __construct(private readonly KeyProviderInterface $provider)
    {
        $this->key = $provider->key();
        if (strlen($this->key) !== 32) {
            throw new RuntimeException('Vault key must be exactly 32 bytes.');
        }
        $this->keyId = substr(hash('sha256', $this->key), 0, 8);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad(),
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::VERSION.':'.$this->keyId.':'.base64_encode($nonce.$tag.$ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new InvalidArgumentException('Malformed vault ciphertext.');
        }

        [, $keyId, $encoded] = $parts;

        if (! hash_equals($this->keyId, $keyId)) {
            throw new RuntimeException(
                "Ciphertext was encrypted with vault key [{$keyId}], active key is [{$this->keyId}]. ".
                'Run `php artisan vault:reencrypt` with the correct VAULT_MASTER_KEY.'
            );
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new InvalidArgumentException('Malformed vault ciphertext payload.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad(),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Vault decryption failed: authentication error or wrong key.');
        }

        return $plaintext;
    }

    /** Decrypt-then-encrypt with the current key (key rotation). */
    public function reencrypt(string $payload): string
    {
        return $this->encrypt($this->decrypt($payload));
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /** Generate a new master key in the recommended format. */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function aad(): string
    {
        return self::VERSION.':'.$this->keyId;
    }
}
