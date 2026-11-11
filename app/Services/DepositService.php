<?php

namespace App\Services;

use App\Enums\Chain;
use App\Enums\DepositStatus;
use App\Events\DepositCredited;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Blockchain\IncomingTransfer;
use App\Services\Security\SettingsService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Turns raw scanner/webhook output into credited, ledger-backed deposits.
 *
 * Idempotency: the deposits table has a unique index on
 * (chain, tx_hash, log_index, asset); credit() re-checks the status inside
 * a row lock, so a deposit can never be credited twice — no matter how many
 * scanners, retries or duplicate webhooks deliver it.
 */
final class DepositService
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly SettingsService $settings,
    ) {
    }

    public function requiredConfirmations(Chain $chain): int
    {
        return (int) $this->settings->get(
            "deposits.confirmations.{$chain->value}",
            $chain->config('confirmations', 1),
        );
    }

    /**
     * Record (or refresh) a detected transfer and credit it when confirmed.
     */
    public function detect(IncomingTransfer $transfer): ?Deposit
    {
        $wallet = $this->resolveWallet($transfer);
        if (! $wallet) {
            return null; // transfer does not belong to us
        }

        $required = $this->requiredConfirmations($transfer->chain);

        $deposit = Deposit::query()->firstOrNew([
            'chain' => $transfer->chain->value,
            'tx_hash' => $transfer->txHash,
            'log_index' => $transfer->logIndex,
            'asset' => $transfer->asset,
        ]);

        $isNew = ! $deposit->exists;

        if (! $isNew && $deposit->status === DepositStatus::Credited) {
            return $deposit; // never touch credited rows here
        }

        $deposit->fill([
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'from_address' => $transfer->fromAddress,
            'to_address' => $transfer->toAddress,
            'amount' => Decimal::norm($transfer->amount),
            'block_number' => $transfer->blockNumber ?? $deposit->block_number,
            'confirmations' => max((int) ($deposit->confirmations ?? 0), $transfer->confirmations),
            'required_confirmations' => $required,
            'raw' => $transfer->raw,
        ]);

        if ($isNew) {
            $deposit->status = DepositStatus::Detected;
            $deposit->detected_at = now();
        } elseif (
            $deposit->status === DepositStatus::Detected
            && $deposit->confirmations > 0
        ) {
            $deposit->status = DepositStatus::Confirming;
        }

        // Reorg: a previously mined transfer came back unconfirmed.
        if (
            ! $isNew
            && $deposit->block_number !== null
            && $transfer->blockNumber === null
            && $deposit->status !== DepositStatus::Credited
        ) {
            $deposit->status = DepositStatus::Orphaned;
        }

        $deposit->save();

        $wallet->forceFill(['last_activity_at' => now()])->saveQuietly();

        if (
            $deposit->status !== DepositStatus::Orphaned
            && $deposit->status !== DepositStatus::Credited
            && $transfer->confirmations >= $required
        ) {
            $this->credit($deposit);
            $deposit->refresh();
        }

        return $deposit;
    }

    /**
     * Re-evaluate confirmations of pending deposits after the chain advanced.
     * @return int number of deposits credited in this pass
     */
    public function refresh(Chain $chain, int $latestHeight): int
    {
        $pending = Deposit::query()
            ->where('chain', $chain->value)
            ->whereIn('status', [DepositStatus::Detected, DepositStatus::Confirming])
            ->whereNotNull('block_number')
            ->get();

        $credited = 0;

        foreach ($pending as $deposit) {
            $confirmations = max(0, $latestHeight - (int) $deposit->block_number + 1);
            $required = (int) ($deposit->required_confirmations ?: $this->requiredConfirmations($chain));

            $deposit->forceFill([
                'confirmations' => $confirmations,
                'status' => $deposit->status === DepositStatus::Detected && $confirmations > 0
                    ? DepositStatus::Confirming
                    : $deposit->status,
            ])->save();

            if ($confirmations >= $required) {
                $this->credit($deposit);
                $credited++;
            }
        }

        return $credited;
    }

    /** Atomically credit a confirmed deposit exactly once. */
    public function credit(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit): void {
            $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === DepositStatus::Credited || $locked->status === DepositStatus::Orphaned) {
                return;
            }

            $this->balances->credit(
                $locked->user_id,
                $locked->asset,
                $locked->amount,
                \App\Enums\TransactionType::Deposit,
                $locked,
                "Deposit {$locked->amount} {$locked->asset} on {$locked->chain->value}",
                ['tx_hash' => $locked->tx_hash, 'chain' => $locked->chain->value],
            );

            $locked->forceFill([
                'status' => DepositStatus::Credited,
                'credited_at' => now(),
            ])->save();

            DB::afterCommit(fn () => event(new DepositCredited($locked)));
        });
    }

    private function resolveWallet(IncomingTransfer $transfer): ?Wallet
    {
        $address = $transfer->toAddress;

        return Wallet::query()
            ->where('chain', $transfer->chain->value)
            ->where(function ($query) use ($address) {
                $query->where('address', $address)
                    ->orWhereRaw('LOWER(address) = ?', [strtolower($address)]);
            })
            ->first();
    }
}
