<?php

namespace App\Services\Security;

use RuntimeException;

/**
 * Reads the vault master key from VAULT_MASTER_KEY.
 * Accepted formats: "base64:..." (recommended) or 64-char hex.
 *
 * IMPORTANT: this key is independent from APP_KEY. Losing it means the HD
 * seed and all API credentials are unrecoverable — keep offline backups.
 */
final class EnvKeyProvider implements KeyProviderInterface
{
    public function key(): string
    {
        $configured = (string) config('vault.master_key');

        if ($configured === '') {
            throw new RuntimeException(
                'VAULT_MASTER_KEY is not set. Generate one with `php artisan vault:key` and store it in .env.'
            );
        }

        $key = match (true) {
            str_starts_with($configured, 'base64:') => base64_decode(substr($configured, 7), true),
            (bool) preg_match('/^[0-9a-fA-F]{64}$/', $configured) => hex2bin($configured),
            default => $configured,
        };

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('VAULT_MASTER_KEY must decode to exactly 32 bytes (generate with `php artisan vault:key`).');
        }

        return $key;
    }

    public function name(): string
    {
        return 'env';
    }
}
