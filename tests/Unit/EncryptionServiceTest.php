<?php

namespace Tests\Unit;

use App\Services\Security\EncryptionService;
use App\Services\Security\KeyProviderInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AES-256-GCM envelope encryption: roundtrip, tamper detection, key
 * identification and rotation behaviour.
 */
final class EncryptionServiceTest extends TestCase
{
    private function provider(string $key): KeyProviderInterface
    {
        return new class($key) implements KeyProviderInterface
        {
            public function __construct(private readonly string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function name(): string
            {
                return 'test';
            }
        };
    }

    private function service(?string $key = null): EncryptionService
    {
        return new EncryptionService($this->provider($key ?? random_bytes(32)));
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $service = $this->service();
        $plaintext = "abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about\nsensitive payload ✓";

        $ciphertext = $service->encrypt($plaintext);

        $this->assertStringStartsWith('v1:', $ciphertext);
        $this->assertStringNotContainsString('abandon', $ciphertext);
        $this->assertSame($plaintext, $service->decrypt($ciphertext));
    }

    public function test_encryption_is_randomized_by_nonce(): void
    {
        $service = $this->service();

        $first = $service->encrypt('same input');
        $second = $service->encrypt('same input');

        $this->assertNotSame($first, $second, 'GCM nonces must make ciphertexts unique.');
        $this->assertSame('same input', $service->decrypt($first));
        $this->assertSame('same input', $service->decrypt($second));
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $service = $this->service();
        $ciphertext = $service->encrypt('do not modify me');

        [$version, $keyId, $encoded] = explode(':', $ciphertext, 3);
        $raw = base64_decode($encoded, true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered = $version . ':' . $keyId . ':' . base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $service->decrypt($tampered);
    }

    public function test_ciphertext_encrypted_with_a_different_key_is_rejected(): void
    {
        $ciphertext = $this->service()->encrypt('secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/vault key/');

        $this->service()->decrypt($ciphertext);
    }

    public function test_malformed_payloads_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->decrypt('not-a-valid-payload');
    }

    public function test_reencrypt_produces_decryptable_ciphertext_with_fresh_nonce(): void
    {
        $service = $this->service();
        $original = $service->encrypt('rotate me');

        $rotated = $service->reencrypt($original);

        $this->assertNotSame($original, $rotated);
        $this->assertSame('rotate me', $service->decrypt($rotated));
    }

    public function test_key_id_is_stable_for_the_same_key_and_changes_for_another(): void
    {
        $key = random_bytes(32);

        $a = $this->service($key);
        $b = $this->service($key);
        $c = $this->service();

        $this->assertSame($a->keyId(), $b->keyId());
        $this->assertNotSame($a->keyId(), $c->keyId());
    }

    public function test_generated_master_key_is_32_bytes_base64(): void
    {
        $generated = EncryptionService::generateKey();

        $this->assertStringStartsWith('base64:', $generated);
        $this->assertSame(32, strlen(base64_decode(substr($generated, 7), true)));
    }
}
