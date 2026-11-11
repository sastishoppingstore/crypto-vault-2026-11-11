<?php

namespace App\Casts;

use App\Services\Security\EncryptionService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

/**
 * Encrypted JSON array attribute (e.g. 2FA recovery code hashes).
 */
class EncryptedArray implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return json_decode(app(EncryptionService::class)->decrypt($value), true) ?? [];
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("Attribute [{$key}] must be an array.");
        }

        return app(EncryptionService::class)->encrypt(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
