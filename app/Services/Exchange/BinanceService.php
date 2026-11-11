<?php

namespace App\Services\Exchange;

use App\Exceptions\BinanceException;
use App\Exceptions\VaultException;
use App\Models\ApiKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Binance Spot REST client (HMAC-SHA256 signed requests).
 *
 * Security notes:
 *  - API key/secret are decrypted only here, in memory;
 *  - the key must have withdrawals enabled + IP whitelist configured;
 *  - 429/418 (rate limit / ban) → retryable exception, the withdrawal job
 *    backs off automatically; 4xx business errors are permanent.
 */
final class BinanceService
{
    private ?ApiKey $credential = null;

    // ------------------------------------------------------------------ public API

    /** Account balances: GET /api/v3/account */
    public function account(): array
    {
        return $this->signed('GET', '/api/v3/account');
    }

    /** Deposit history: GET /sapi/v1/capital/deposit/hisrec */
    public function depositHistory(array $params = []): array
    {
        return $this->signed('GET', '/sapi/v1/capital/deposit/hisrec', $params);
    }

    /** Deposit address for a coin/network (treasury tooling). */
    public function depositAddress(string $coin, ?string $network = null): array
    {
        return $this->signed('GET', '/sapi/v1/capital/deposit/address', array_filter([
            'coin' => $coin,
            'network' => $network,
        ]));
    }

    /**
     * Submit a withdrawal: POST /sapi/v1/capital/withdraw/apply
     * $withdrawOrderId is echoed back by history lookups — used for idempotency.
     *
     * @return array{id: string}
     */
    public function withdraw(
        string $coin,
        string $address,
        string $amount,
        ?string $network = null,
        ?string $addressTag = null,
        ?string $withdrawOrderId = null,
    ): array {
        return $this->signed('POST', '/sapi/v1/capital/withdraw/apply', array_filter([
            'coin' => $coin,
            'address' => $address,
            'amount' => $amount,
            'network' => $network,
            'addressTag' => $addressTag,
            'withdrawOrderId' => $withdrawOrderId,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /** Withdrawal history: GET /sapi/v1/capital/withdraw/history */
    public function withdrawHistory(array $params = []): array
    {
        return $this->signed('GET', '/sapi/v1/capital/withdraw/history', $params);
    }

    /** Find one withdrawal by our client-side order id. */
    public function findWithdrawal(string $coin, string $withdrawOrderId): ?array
    {
        $history = $this->withdrawHistory([
            'coin' => $coin,
            'withdrawOrderId' => $withdrawOrderId,
            'limit' => 10,
        ]);

        return $history[0] ?? null;
    }

    /** Public connectivity check (no signature). */
    public function ping(): bool
    {
        return Http::timeout(10)->get($this->baseUrl().'/api/v3/ping')->successful();
    }

    /** Public system status: /sapi/v1/system/status */
    public function systemStatus(): array
    {
        $response = Http::timeout(10)->get($this->baseUrl().'/sapi/v1/system/status');

        return $response->successful() ? (array) $response->json() : [];
    }

    public function isTestnet(): bool
    {
        return (bool) config('exchanges.binance.testnet', false);
    }

    // ------------------------------------------------------------------ internals

    private function signed(string $method, string $path, array $params = []): array
    {
        $credential = $this->credential();

        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = (int) config('exchanges.binance.recv_window', 5000);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, $credential->secret);
        $url = $this->baseUrl().$path.'?'.$query.'&signature='.$signature;

        $response = Http::withHeaders(['X-MBX-APIKEY' => $credential->key])
            ->timeout((int) config('exchanges.binance.timeout', 15))
            ->retry((int) config('exchanges.binance.retry.times', 3), (int) config('exchanges.binance.retry.sleep_ms', 500), function (\Throwable $e) {
                return $e instanceof ConnectionException;
            }, throw: false)
            ->send($method, $url);

        $credential->markUsed();

        $status = $response->status();
        $json = $response->json() ?? [];

        if ($status === 429 || $status === 418) {
            Log::warning('Binance rate limit', ['status' => $status, 'path' => $path]);

            throw new BinanceException('Binance rate limit reached (HTTP '.$status.').', null, true, $status);
        }

        if (! $response->successful()) {
            $apiCode = $json['code'] ?? null;
            $message = (string) ($json['msg'] ?? 'HTTP '.$status);

            throw new BinanceException(
                "Binance API error [{$apiCode}]: {$message}",
                $apiCode,
                BinanceException::isRetryableCode($apiCode),
                $status,
            );
        }

        return $json;
    }

    private function credential(): ApiKey
    {
        if ($this->credential) {
            return $this->credential;
        }

        $credential = ApiKey::query()->active()->where('exchange', 'binance')->first();

        if (! $credential) {
            throw new VaultException('No active Binance API key configured. Add one in the admin panel (Settings → API keys).');
        }

        return $this->credential = $credential;
    }

    private function baseUrl(): string
    {
        return $this->isTestnet()
            ? rtrim((string) config('exchanges.binance.testnet_url'), '/')
            : rtrim((string) config('exchanges.binance.base_url'), '/');
    }
}
