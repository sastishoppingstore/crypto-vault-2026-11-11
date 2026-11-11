<?php

namespace App\Services\Security;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed, Redis-cached access to the `settings` table.
 * Read via dot notation: settings('withdrawal.assets.USDT.fee').
 */
final class SettingsService
{
    private const CACHE_KEY = 'vault.settings.all';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return Setting::query()
                ->get(['key', 'value'])
                ->mapWithKeys(fn (Setting $s) => [$s->key => $s->value])
                ->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        // Settings keys themselves may contain dots (e.g. "withdrawal.assets").
        // Resolve the longest prefix that exists as an exact key, then descend
        // into the remaining segments of the stored value:
        //   "withdrawal.assets.USDT" -> all()['withdrawal.assets']['USDT']
        $segments = explode('.', $key);
        $rest = [];

        while ($segments !== []) {
            $prefix = implode('.', $segments);
            if (array_key_exists($prefix, $all)) {
                $value = $all[$prefix];

                return $rest === [] ? $value : data_get($value, implode('.', $rest), $default);
            }

            array_unshift($rest, array_pop($segments));
        }

        return $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function decimal(string $key, string $default = '0'): string
    {
        return (string) $this->get($key, $default);
    }

    /** @return array<string, mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function set(string $key, mixed $value, string $group = 'general', ?string $description = null): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            array_filter([
                'value' => $value,
                'group' => $group,
                'description' => $description,
            ], fn ($v) => ! is_null($v)),
        );

        $this->flush();
    }

    /** @param  array<string, mixed>  $values */
    public function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        }

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
