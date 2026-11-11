<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Polling cursor for scanners / exchange sync jobs.
 * `position` holds a block number (chains) or a millisecond timestamp (Binance).
 */
class SyncCursor extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'position', 'meta', 'updated_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public static function positionOf(string $key, string $default = '0'): string
    {
        return (string) (static::query()->where('key', $key)->value('position') ?? $default);
    }

    public static function setPosition(string $key, string|int $position, ?array $meta = null): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['position' => (string) $position, 'meta' => $meta, 'updated_at' => now()],
        );
    }
}
