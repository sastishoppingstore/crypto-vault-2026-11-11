<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Exchange API credentials — encrypted at rest (AES-256-GCM).
 * Only BinanceService / ExchangeManager should ever read them.
 */
class ApiKey extends Model
{
    protected $fillable = [
        'name',
        'exchange',
        'key',
        'secret',
        'options',
        'allowed_ips',
        'is_active',
        'last_used_at',
        'last_rotated_at',
    ];

    protected $hidden = ['key', 'secret'];

    protected function casts(): array
    {
        return [
            'key' => EncryptedString::class,
            'secret' => EncryptedString::class,
            'options' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'last_rotated_at' => 'datetime',
        ];
    }

    /** @param  Builder<ApiKey>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array{key: string, secret: string} */
    public function credentials(): array
    {
        return [
            'key' => (string) $this->key,
            'secret' => (string) $this->secret,
        ];
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
