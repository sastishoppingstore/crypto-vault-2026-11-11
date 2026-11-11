<?php

namespace App\Enums;

/**
 * Supported blockchains. String values are used in DB columns and config.
 */
enum Chain: string
{
    case Btc = 'btc';
    case Eth = 'eth';
    case Bsc = 'bsc';
    case Polygon = 'polygon';
    case Tron = 'tron';

    /** Address derivation family: bitcoin | evm | tron */
    public function family(): string
    {
        return match ($this) {
            self::Btc => 'bitcoin',
            self::Eth, self::Bsc, self::Polygon => 'evm',
            self::Tron => 'tron',
        };
    }

    public function label(): string
    {
        return (string) $this->config('label', strtoupper($this->value));
    }

    public function nativeAsset(): string
    {
        return (string) $this->config('asset', strtoupper($this->value));
    }

    /** Read a value from config/blockchain.php for this chain. */
    public function config(string $key, mixed $default = null): mixed
    {
        return config("blockchain.chains.{$this->value}.{$key}", $default);
    }

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    /** @return array<Chain> */
    public static function enabledChains(): array
    {
        return collect(config('blockchain.chains', []))
            ->filter(fn (array $cfg) => $cfg['enabled'] ?? false)
            ->keys()
            ->map(fn (string $k) => self::from($k))
            ->values()
            ->all();
    }

    /** Tokens (ERC20/BEP20/TRC20) accepted on this chain. */
    public function tokens(): array
    {
        return (array) $this->config('tokens', []);
    }

    public function token(string $symbol): ?array
    {
        foreach ($this->tokens() as $token) {
            if (strcasecmp($token['symbol'], $symbol) === 0) {
                return $token;
            }
        }

        return null;
    }

    /** All assets (native + tokens) accepted on this chain. @return array<string> */
    public function assets(): array
    {
        return array_merge(
            [$this->nativeAsset()],
            array_column($this->tokens(), 'symbol')
        );
    }
}
