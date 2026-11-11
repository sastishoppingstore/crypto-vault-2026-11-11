<?php

namespace App\Services\Exchange;

use App\Exceptions\VaultException;
use App\Models\ApiKey;

/**
 * Multi-exchange rail via the CCXT library (optional secondary provider).
 * Credentials come from the encrypted `api_keys` table.
 */
final class ExchangeManager
{
    /** @var array<string, \ccxt\Exchange> */
    private array $instances = [];

    public function exchange(string $name): \ccxt\Exchange
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (! config('exchanges.ccxt.enabled', false)) {
            throw new VaultException('CCXT rail is disabled (EXCHANGE_CCXT_ENABLED=false).');
        }

        $credential = ApiKey::query()->active()->where('exchange', $name)->first();
        if (! $credential) {
            throw new VaultException("No active API key for exchange [{$name}].");
        }

        $class = '\\ccxt\\'.strtolower($name);
        if (! class_exists($class)) {
            throw new VaultException("CCXT does not support exchange [{$name}].");
        }

        /** @var \ccxt\Exchange $exchange */
        $exchange = new $class([
            'apiKey' => $credential->key,
            'secret' => $credential->secret,
            'enableRateLimit' => true,
        ]);

        if (config('exchanges.binance.testnet') && str_contains($name, 'binance')) {
            $exchange->set_sandbox_mode(true);
        }

        return $this->instances[$name] = $exchange;
    }

    public function balances(string $name): array
    {
        return $this->exchange($name)->fetch_balance();
    }

    public function deposits(string $name, ?string $code = null, ?int $since = null): array
    {
        return $this->exchange($name)->fetch_deposits($code, $since);
    }

    public function withdrawals(string $name, ?string $code = null, ?int $since = null): array
    {
        return $this->exchange($name)->fetch_withdrawals($code, $since);
    }

    public function withdraw(string $name, string $code, string $amount, string $address, array $params = []): array
    {
        return $this->exchange($name)->withdraw($code, (float) $amount, $address, null, $params);
    }
}
