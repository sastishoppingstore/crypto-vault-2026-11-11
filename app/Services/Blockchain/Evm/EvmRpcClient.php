<?php

namespace App\Services\Blockchain\Evm;

use App\Enums\Chain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal JSON-RPC client for EVM chains (Ethereum/BSC/Polygon).
 *
 * NOTE: for production, point rpc_url at a private provider (Alchemy, Infura,
 * QuickNode, ...). Public endpoints are aggressively rate limited.
 */
final class EvmRpcClient
{
    private int $id = 0;

    public function call(Chain $chain, string $method, array $params = [], int $timeout = 20): mixed
    {
        $url = (string) $chain->config('rpc_url');
        if ($url === '') {
            throw new RuntimeException("No rpc_url configured for chain [{$chain->value}].");
        }

        $response = $this->http($timeout)->post($url, [
            'jsonrpc' => '2.0',
            'id' => ++$this->id,
            'method' => $method,
            'params' => $params,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("EVM RPC {$method} failed with HTTP {$response->status()} on {$chain->value}.");
        }

        $json = $response->json();
        if (isset($json['error'])) {
            throw new RuntimeException("EVM RPC {$method} error: ".json_encode($json['error']));
        }

        return $json['result'] ?? null;
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->retry(3, 400, throw: false)
            ->acceptJson()
            ->asJson();
    }

    public function blockNumber(Chain $chain): int
    {
        return (int) self::hexToDec((string) $this->call($chain, 'eth_blockNumber'));
    }

    public function gasPrice(Chain $chain): string
    {
        return self::hexToDec((string) $this->call($chain, 'eth_gasPrice'));
    }

    public function nonce(Chain $chain, string $address): string
    {
        return self::hexToDec((string) $this->call($chain, 'eth_getTransactionCount', [$address, 'pending']));
    }

    public function estimateGas(Chain $chain, array $tx): string
    {
        return self::hexToDec((string) $this->call($chain, 'eth_estimateGas', [$tx]));
    }

    public function sendRawTransaction(Chain $chain, string $rawHex): string
    {
        return (string) $this->call($chain, 'eth_sendRawTransaction', [$rawHex]);
    }

    public function transactionReceipt(Chain $chain, string $txHash): ?array
    {
        return $this->call($chain, 'eth_getTransactionReceipt', [$txHash]);
    }

    // ------------------------------------------------------------------ helpers

    /** Hex quantity ("0x..." or "0x1d") -> decimal string. */
    public static function hexToDec(string $hex): string
    {
        $hex = strtolower(trim($hex));
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        $hex = ltrim($hex, '0');

        return $hex === '' ? '0' : gmp_strval(gmp_init($hex, 16), 10);
    }

    /** Decimal string/int -> minimal hex quantity with 0x prefix. */
    public static function decToHex(string|int $dec): string
    {
        $gmp = gmp_init((string) $dec, 10);

        return gmp_cmp($gmp, 0) === 0 ? '0x0' : '0x'.gmp_strval($gmp, 16);
    }

    /** 20-byte address left-padded to 32 bytes (topic/calldata encoding). */
    public static function padAddress(string $address): string
    {
        $address = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);

        return str_pad($address, 64, '0', STR_PAD_LEFT);
    }

    /** Unsigned integer left-padded to 32 bytes. */
    public static function padUint(string|int $value): string
    {
        $hex = gmp_strval(gmp_init((string) $value, 10), 16);

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }
}
