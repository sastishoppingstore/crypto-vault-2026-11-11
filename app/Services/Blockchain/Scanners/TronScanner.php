<?php

namespace App\Services\Blockchain\Scanners;

use App\Enums\Chain;
use App\Models\Wallet;
use App\Services\Blockchain\AddressValidator;
use App\Services\Blockchain\IncomingTransfer;
use App\Services\Blockchain\ScannerInterface;
use App\Support\Decimal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tron deposit scanner via TronGrid (native TRX + TRC20).
 * Get a free API key (dashboard.trongrid.io) — anonymous requests are
 * heavily rate limited. TRON finality is ~19 blocks.
 */
final class TronScanner implements ScannerInterface
{
    private const PAGE_LIMIT = 50;

    public function __construct(private readonly AddressValidator $validator)
    {
    }

    public function scan(Chain $chain): iterable
    {
        $wallets = Wallet::query()
            ->where('chain', $chain->value)
            ->where('is_active', true)
            ->get();

        if ($wallets->isEmpty()) {
            return [];
        }

        $tip = $this->latestHeight($chain);
        $transfers = [];

        foreach ($wallets as $wallet) {
            $hex = $this->validator->tronToHex($wallet->address);
            if (! $hex) {
                continue;
            }

            array_push(
                $transfers,
                ...$this->nativeTransfers($chain, $wallet->address, strtolower($hex), $tip),
                ...$this->trc20Transfers($chain, $wallet->address, $tip),
            );
        }

        return $transfers;
    }

    public function latestHeight(Chain $chain): int
    {
        $block = $this->post($chain, '/wallet/getnowblock', []);

        return (int) ($block['block_header']['raw_data']['number'] ?? 0);
    }

    /** @return array<int, IncomingTransfer> */
    private function nativeTransfers(Chain $chain, string $address, string $hexAddress, int $tip): array
    {
        $response = $this->get($chain, "/v1/accounts/{$address}/transactions", [
            'only_to' => 'true',
            'limit' => self::PAGE_LIMIT,
        ]);

        $transfers = [];
        $asset = $chain->nativeAsset();

        foreach ($response['data'] ?? [] as $tx) {
            $contract = $tx['raw_data']['contract'][0] ?? null;
            if (! $contract || ($contract['type'] ?? '') !== 'TransferContract') {
                continue;
            }

            $value = $contract['parameter']['value'] ?? [];
            if (strtolower((string) ($value['to_address'] ?? '')) !== $hexAddress) {
                continue;
            }

            $block = (int) ($tx['blockNumber'] ?? 0);
            $amount = Decimal::fromBaseUnits((string) ($value['amount'] ?? '0'), (int) $chain->config('decimals', 6));

            if (! Decimal::isPositive($amount)) {
                continue;
            }

            $transfers[] = new IncomingTransfer(
                chain: $chain,
                asset: $asset,
                txHash: (string) ($tx['txID'] ?? ''),
                logIndex: 0,
                fromAddress: $this->validator->tronFromHex((string) ($value['owner_address'] ?? '')),
                toAddress: $address,
                amount: $amount,
                blockNumber: $block > 0 ? $block : null,
                confirmations: $block > 0 ? max(0, $tip - $block + 1) : 0,
                raw: ['type' => 'trx'],
            );
        }

        return $transfers;
    }

    /** @return array<int, IncomingTransfer> */
    private function trc20Transfers(Chain $chain, string $address, int $tip): array
    {
        $response = $this->get($chain, "/v1/accounts/{$address}/transactions/trc20", [
            'only_to' => 'true',
            'limit' => self::PAGE_LIMIT,
        ]);

        $tokens = collect($chain->tokens())->keyBy(fn (array $t) => strtolower($t['address']));
        $transfers = [];

        foreach ($response['data'] ?? [] as $item) {
            $tokenAddress = strtolower((string) ($item['token_info']['address'] ?? ''));
            $token = $tokens[$tokenAddress] ?? null;
            if (! $token) {
                continue;
            }

            $txId = (string) ($item['transaction_id'] ?? '');
            $block = (int) ($item['block'] ?? 0);
            if ($block <= 0) {
                // TronGrid sometimes omits the block — resolve it once.
                $block = $this->blockNumberOf($chain, $txId);
            }

            $decimals = (int) ($item['token_info']['decimals'] ?? $token['decimals']);
            $amount = Decimal::fromBaseUnits((string) ($item['value'] ?? '0'), $decimals);

            if (! Decimal::isPositive($amount) || $txId === '') {
                continue;
            }

            $transfers[] = new IncomingTransfer(
                chain: $chain,
                asset: (string) $token['symbol'],
                txHash: $txId,
                logIndex: 0,
                fromAddress: (string) ($item['from'] ?? ''),
                toAddress: $address,
                amount: $amount,
                blockNumber: $block > 0 ? $block : null,
                confirmations: $block > 0 ? max(0, $tip - $block + 1) : 0,
                raw: ['type' => 'trc20', 'contract' => $token['address']],
            );
        }

        return $transfers;
    }

    private function blockNumberOf(Chain $chain, string $txId): int
    {
        $info = $this->post($chain, '/wallet/gettransactioninfobyid', ['value' => $txId]);

        return (int) ($info['blockNumber'] ?? 0);
    }

    // ------------------------------------------------------------ HTTP helpers

    /** @return array<string, mixed> */
    private function get(Chain $chain, string $path, array $query): array
    {
        $response = $this->http($chain)->get(rtrim((string) $chain->config('api_url'), '/').$path, $query);

        if (! $response->successful()) {
            throw new RuntimeException("TronGrid {$path} failed: HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    /** @return array<string, mixed> */
    private function post(Chain $chain, string $path, array $body): array
    {
        $response = $this->http($chain)->post(rtrim((string) $chain->config('api_url'), '/').$path, $body);

        if (! $response->successful()) {
            throw new RuntimeException("TronGrid {$path} failed: HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    private function http(Chain $chain): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout(20)->retry(2, 700, throw: false)->acceptJson()->asJson();

        if ($apiKey = $chain->config('api_key')) {
            $request = $request->withHeaders(['TRON-PRO-API-KEY' => $apiKey]);
        }

        return $request;
    }
}
