<?php

namespace App\Casts;

use App\Services\Security\EncryptionService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Transparently AES-256-GCM encrypts model attributes at rest.
 * The ciphertext never leaves the vault key envelope (EncryptionService).
 */
class EncryptedString implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : app(EncryptionService::class)->decrypt($value);
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(EncryptionService::class)->encrypt((string) $value);
    }
}
