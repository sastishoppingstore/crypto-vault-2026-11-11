<?php

namespace App\Services\Blockchain\Scanners;

use App\Enums\Chain;
use App\Models\Wallet;
use App\Services\Blockchain\IncomingTransfer;
use App\Services\Blockchain\ScannerInterface;
use App\Support\Decimal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bitcoin deposit scanner using an Esplora-compatible API
 * (mempool.space by default; self-hosted Esplora/bitcoind recommended for
 * production). Watches the user addresses directly.
 *
 * Only confirmed transactions are creditable (DepositService checks
 * confirmations vs the chain requirement). Mempool tx (confirmations 0)
 * are recorded as "detected" and credited automatically once mined.
 */
final class BitcoinScanner implements ScannerInterface
{
    private const MAX_PAGES = 4; // 4 x 25 txs — recent history is enough

    public function scan(Chain $chain): iterable
    {
        $api = rtrim((string) $chain->config('api_url'), '/');
        $addresses = Wallet::query()
            ->where('chain', $chain->value)
            ->where('is_active', true)
            ->pluck('address');

        if ($addresses->isEmpty()) {
            return [];
        }

        $tip = $this->latestHeight($chain);
        $asset = $chain->nativeAsset();
        $transfers = [];

        foreach ($addresses as $address) {
            $afterTxId = null;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $url = "{$api}/address/{$address}/txs".($afterTxId ? "?after_txid={$afterTxId}" : '');
                $txs = Http::timeout(20)->retry(2, 500, throw: false)->get($url)->json();

                if (! is_array($txs) || $txs === []) {
                    break;
                }

                foreach ($txs as $tx) {
                    $confirmed = (bool) ($tx['status']['confirmed'] ?? false);
                    $height = (int) ($tx['status']['block_height'] ?? 0);
                    $confirmations = $confirmed && $height > 0 ? max(0, $tip - $height + 1) : 0;

                    foreach (($tx['vout'] ?? []) as $index => $out) {
                        if (($out['scriptpubkey_address'] ?? null) !== $address) {
                            continue;
                        }

                        $sats = (string) ($out['value'] ?? '0');
                        if ($sats === '0' || ! ctype_digit($sats)) {
                            continue;
                        }

                        $transfers[] = new IncomingTransfer(
                            chain: $chain,
                            asset: $asset,
                            txHash: (string) $tx['txid'],
                            logIndex: (int) $index,
                            fromAddress: $tx['vin'][0]['prevout']['scriptpubkey_address'] ?? null,
                            toAddress: $address,
                            amount: Decimal::fromBaseUnits($sats, 8),
                            blockNumber: $confirmed ? $height : null,
                            confirmations: $confirmations,
                            raw: ['type' => 'btc', 'mempool' => ! $confirmed],
                        );
                    }
                }

                $afterTxId = count($txs) >= 25 ? (string) end($txs)['txid'] : null;
                if (! $afterTxId) {
                    break;
                }
            }
        }

        return $transfers;
    }

    public function latestHeight(Chain $chain): int
    {
        $api = rtrim((string) $chain->config('api_url'), '/');
        $response = Http::timeout(15)->retry(2, 500, throw: false)->get("{$api}/blocks/tip/height");

        if (! $response->successful()) {
            throw new RuntimeException("Bitcoin API tip request failed: HTTP {$response->status()}.");
        }

        return (int) $response->body();
    }
}
