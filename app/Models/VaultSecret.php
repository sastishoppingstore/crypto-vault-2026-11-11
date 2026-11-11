<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Encrypted key material: HD seed mnemonic, hot wallet private keys, etc.
 * Values are encrypted with the vault key (EncryptionService) and never
 * exposed through the API.
 */
class VaultSecret extends Model
{
    protected $fillable = ['name', 'value', 'meta', 'rotated_at'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => EncryptedString::class,
            'meta' => 'array',
            'rotated_at' => 'datetime',
        ];
    }

    public static function get(string $name, bool $required = true): ?string
    {
        $secret = static::query()->where('name', $name)->first();

        if (! $secret && $required) {
            throw new \RuntimeException("Vault secret [{$name}] is not configured. Run `php artisan vault:generate-seed`.");
        }

        return $secret?->value;
    }

    public static function put(string $name, string $value, ?array $meta = null): void
    {
        static::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value, 'meta' => $meta, 'rotated_at' => now()],
        );
        Cache::forget("vault.secret.{$name}");
    }
}
