<?php

namespace App\Services\Blockchain\Scanners;

use App\Enums\Chain;
use App\Models\SyncCursor;
use App\Models\Wallet;
use App\Services\Blockchain\Evm\EvmRpcClient;
use App\Services\Blockchain\IncomingTransfer;
use App\Services\Blockchain\ScannerInterface;
use App\Support\Decimal;

/**
 * EVM deposit scanner (native + ERC20/BEP20 transfers).
 *
 * Strategy: walk a bounded block range per run (cursor persisted in
 * sync_cursors). Native transfers come from block bodies; token transfers
 * from a single eth_getLogs call per range. Transfers already seen are
 * re-reported only for confirmation refresh — DepositService is idempotent.
 *
 * Scale-up path: for high volume chains replace the block walk with an
 * indexer webhook (Alchemy "Address Activity") posting to /api/webhooks/...
 */
final class EvmScanner implements ScannerInterface
{
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    private const FIRST_RUN_LOOKBACK = 120; // blocks scanned on the very first run

    public function __construct(private readonly EvmRpcClient $rpc)
    {
    }

    public function scan(Chain $chain): iterable
    {
        $addresses = Wallet::query()
            ->where('chain', $chain->value)
            ->where('is_active', true)
            ->pluck('address')
            ->map(fn (string $a) => strtolower($a))
            ->all();

        if ($addresses === []) {
            return [];
        }

        $latest = $this->latestHeight($chain);
        if ($latest <= 0) {
            return [];
        }

        $safety = (int) config('blockchain.scanning.reorg_safety_blocks', 5);
        $maxPerRun = max(10, (int) config('blockchain.scanning.max_blocks_per_run', 300));

        $cursorKey = "evm.{$chain->value}.block";
        $cursor = (int) SyncCursor::positionOf($cursorKey, '0');

        $from = $cursor > 0 ? $cursor + 1 : max(1, $latest - self::FIRST_RUN_LOOKBACK);
        $to = min($latest - $safety, $from + $maxPerRun - 1);

        if ($to < $from) {
            return [];
        }

        $transfers = array_merge(
            $this->nativeTransfers($chain, $addresses, $from, $to, $latest),
            $this->tokenTransfers($chain, $addresses, $from, $to, $latest),
        );

        SyncCursor::setPosition($cursorKey, $to);

        return $transfers;
    }

    public function latestHeight(Chain $chain): int
    {
        return $this->rpc->blockNumber($chain);
    }

    /** @param  array<int, string>  $addresses  @return array<int, IncomingTransfer> */
    private function nativeTransfers(Chain $chain, array $addresses, int $from, int $to, int $latest): array
    {
        $lookup = array_flip($addresses);
        $decimals = (int) $chain->config('decimals', 18);
        $transfers = [];

        for ($blockNumber = $from; $blockNumber <= $to; $blockNumber++) {
            $block = $this->rpc->call($chain, 'eth_getBlockByNumber', [EvmRpcClient::decToHex($blockNumber), true], timeout: 30);
            if (! is_array($block) || empty($block['transactions'])) {
                continue;
            }

            foreach ($block['transactions'] as $tx) {
                $toAddress = strtolower((string) ($tx['to'] ?? ''));
                if ($toAddress === '' || ! isset($lookup[$toAddress])) {
                    continue;
                }

                $amount = Decimal::fromBaseUnits(EvmRpcClient::hexToDec((string) ($tx['value'] ?? '0x0')), $decimals);
                if (! Decimal::isPositive($amount)) {
                    continue;
                }

                $transfers[] = new IncomingTransfer(
                    chain: $chain,
                    asset: $chain->nativeAsset(),
                    txHash: (string) $tx['hash'],
                    logIndex: 0,
                    fromAddress: strtolower((string) ($tx['from'] ?? '')),
                    toAddress: $toAddress,
                    amount: $amount,
                    blockNumber: $blockNumber,
                    confirmations: max(0, $latest - $blockNumber + 1),
                    raw: ['type' => 'native'],
                );
            }
        }

        return $transfers;
    }

    /** @param  array<int, string>  $addresses  @return array<int, IncomingTransfer> */
    private function tokenTransfers(Chain $chain, array $addresses, int $from, int $to, int $latest): array
    {
        $tokens = collect($chain->tokens())
            ->keyBy(fn (array $t) => strtolower($t['address']));

        if ($tokens->isEmpty()) {
            return [];
        }

        $lookup = array_flip($addresses);

        $logs = $this->rpc->call($chain, 'eth_getLogs', [[
            'fromBlock' => EvmRpcClient::decToHex($from),
            'toBlock' => EvmRpcClient::decToHex($to),
            'address' => $tokens->keys()->values()->all(),
            'topics' => [self::TRANSFER_TOPIC],
        ]], timeout: 30);

        if (! is_array($logs)) {
            return [];
        }

        $transfers = [];

        foreach ($logs as $log) {
            $topics = $log['topics'] ?? [];
            if (count($topics) < 3) {
                continue;
            }

            $toAddress = strtolower('0x'.substr((string) $topics[2], 26));
            if (! isset($lookup[$toAddress])) {
                continue;
            }

            $token = $tokens[strtolower((string) $log['address'])] ?? null;
            if (! $token) {
                continue;
            }

            $blockNumber = (int) EvmRpcClient::hexToDec((string) $log['blockNumber']);
            $amount = Decimal::fromBaseUnits(
                EvmRpcClient::hexToDec((string) $log['data']),
                (int) $token['decimals'],
            );

            if (! Decimal::isPositive($amount)) {
                continue;
            }

            $transfers[] = new IncomingTransfer(
                chain: $chain,
                asset: (string) $token['symbol'],
                txHash: (string) $log['transactionHash'],
                logIndex: (int) EvmRpcClient::hexToDec((string) $log['logIndex']),
                fromAddress: strtolower('0x'.substr((string) $topics[1], 26)),
                toAddress: $toAddress,
                amount: $amount,
                blockNumber: $blockNumber,
                confirmations: max(0, $latest - $blockNumber + 1),
                raw: ['type' => 'token', 'contract' => $log['address']],
            );
        }

        return $transfers;
    }
}
