<?php

namespace App\Jobs;

use App\Enums\TransactionType;
use App\Models\ApiKey;
use App\Models\SyncCursor;
use App\Models\Transaction;
use App\Services\Exchange\BinanceService;
use App\Support\Decimal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Treasury sync: pulls the exchange account's deposit/withdrawal history into
 * the ledger as reference rows (user_id = NULL, ExchangeDeposit/Withdrawal).
 *
 * This keeps platform-treasury flows auditable next to user balances without
 * touching user wallets. Cursors live in sync_cursors.
 */
class SyncExchangeDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 240;

    /** @var array<int, int> */
    public array $backoff = [60, 240];

    public function __construct()
    {
        $this->onQueue('deposits');
    }

    public function handle(BinanceService $binance): void
    {
        if (! ApiKey::query()->active()->where('exchange', 'binance')->exists()) {
            return; // no exchange credentials configured — nothing to sync
        }

        $coins = (array) config('exchanges.binance.treasury_coins', ['USDT']);

        foreach ($coins as $coin) {
            try {
                $this->syncDeposits($binance, $coin);
                $this->syncWithdrawals($binance, $coin);
            } catch (\Throwable $e) {
                Log::warning('Treasury sync failed for coin', ['coin' => $coin, 'error' => $e->getMessage()]);
            }
        }
    }

    private function syncDeposits(BinanceService $binance, string $coin): void
    {
        $cursorKey = "binance.treasury.deposits.{$coin}";
        $cursor = (int) SyncCursor::positionOf($cursorKey, '0');
        $start = $cursor > 0 ? $cursor + 1 : (int) (microtime(true) * 1000) - 86_400_000;

        $records = $binance->depositHistory([
            'coin' => $coin,
            'startTime' => $start,
            'limit' => 1000,
        ]);

        $maxInsertTime = $cursor;

        foreach ($records as $record) {
            $insertTime = (int) ($record['insertTime'] ?? 0);
            $maxInsertTime = max($maxInsertTime, $insertTime);

            // Only completed credits enter the ledger; pending stays on Binance.
            if ((int) ($record['status'] ?? 0) !== 1) {
                continue;
            }

            if ($this->alreadySynced((string) $record['id'])) {
                continue;
            }

            Transaction::query()->create([
                'user_id' => null,
                'type' => TransactionType::ExchangeDeposit,
                'asset' => $coin,
                'amount' => Decimal::norm((string) $record['amount']),
                'description' => "Binance deposit #{$record['id']} ({$record['network']})",
                'meta' => [
                    'provider' => 'binance',
                    'provider_id' => (string) $record['id'],
                    'tx_id' => $record['txId'] ?? null,
                    'network' => $record['network'] ?? null,
                    'address' => $record['address'] ?? null,
                ],
            ]);
        }

        SyncCursor::setPosition($cursorKey, $maxInsertTime);
    }

    private function syncWithdrawals(BinanceService $binance, string $coin): void
    {
        $cursorKey = "binance.treasury.withdrawals.{$coin}";
        $cursor = (int) SyncCursor::positionOf($cursorKey, '0');
        $start = $cursor > 0 ? $cursor + 1 : (int) (microtime(true) * 1000) - 86_400_000;

        $records = $binance->withdrawHistory([
            'coin' => $coin,
            'startTime' => $start,
            'limit' => 1000,
        ]);

        $maxApplyTime = $cursor;

        foreach ($records as $record) {
            $applyTime = (int) ($record['applyTime'] ?? 0);
            $maxApplyTime = max($maxApplyTime, $applyTime);

            // status 6 = completed
            if ((int) ($record['status'] ?? 0) !== 6) {
                continue;
            }

            if ($this->alreadySynced((string) $record['id'])) {
                continue;
            }

            Transaction::query()->create([
                'user_id' => null,
                'type' => TransactionType::ExchangeWithdrawal,
                'asset' => $coin,
                'amount' => Decimal::neg(Decimal::norm((string) $record['amount'])),
                'description' => "Binance withdrawal #{$record['id']}",
                'meta' => [
                    'provider' => 'binance',
                    'provider_id' => (string) $record['id'],
                    'tx_id' => $record['txId'] ?? null,
                    'network' => $record['network'] ?? null,
                    'address' => $record['address'] ?? null,
                ],
            ]);
        }

        SyncCursor::setPosition($cursorKey, $maxApplyTime);
    }

    private function alreadySynced(string $providerId): bool
    {
        return Transaction::query()
            ->whereIn('type', [TransactionType::ExchangeDeposit, TransactionType::ExchangeWithdrawal])
            ->where('meta->provider_id', $providerId)
            ->exists();
    }
}
