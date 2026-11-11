<?php

namespace App\Services;

use App\Enums\Chain;
use App\Enums\WithdrawalStatus;
use App\Events\WithdrawalCompleted;
use App\Events\WithdrawalFailed;
use App\Events\WithdrawalRequested;
use App\Events\WithdrawalStatusChanged;
use App\Exceptions\BinanceException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\VaultException;
use App\Exceptions\WithdrawalException;
use App\Jobs\ProcessWithdrawalJob;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\AddressValidator;
use App\Services\Blockchain\Evm\EvmRpcClient;
use App\Services\Blockchain\Evm\EvmSender;
use App\Services\Exchange\BinanceService;
use App\Services\Security\AuditLogger;
use App\Services\Security\SettingsService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Withdrawal lifecycle:
 *
 *   request()  -> balance check + lock funds (available -> locked)
 *                 small amounts (auto_limit) -> PendingAuto  -> ProcessWithdrawalJob
 *                 larger amounts             -> PendingManual (admin decides)
 *   approve()  -> admin action, audit logged, -> ProcessWithdrawalJob
 *   reject()   -> admin action, funds unlocked
 *   process()  -> send via rail (Binance API | on-chain hot wallet)
 *   refresh()  -> poll rail status; complete (settle lock) or fail (unlock)
 *
 * Every state transition happens under a row lock and writes an audit trail
 * entry — a withdrawal can never be sent twice (idempotency key + status
 * guard + provider side withdrawOrderId).
 */
final class WithdrawalService
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly AddressValidator $validator,
        private readonly BinanceService $binance,
        private readonly EvmRpcClient $rpc,
        private readonly EvmSender $evmSender,
    ) {
    }

    // ------------------------------------------------------------------ queries

    /** @return array<string, mixed> */
    public function assetConfig(string $asset): array
    {
        return (array) $this->settings->get('withdrawal.assets.'.strtoupper($asset), []);
    }

    // ------------------------------------------------------------------- intake

    /**
     * @param  array{asset: string, amount: string, to_address: string, memo?: ?string, idempotency_key?: ?string}  $data
     */
    public function request(User $user, array $data): Withdrawal
    {
        $asset = strtoupper(trim($data['asset']));
        $config = $this->assetConfig($asset);

        if ($config === [] || ! ($config['enabled'] ?? false)) {
            throw new VaultException("Withdrawals for {$asset} are currently disabled.");
        }
        if ($user->isFrozen()) {
            throw new VaultException('Your account is frozen. Please contact support.');
        }

        $amount = Decimal::norm((string) $data['amount']);
        if (! Decimal::isPositive($amount)) {
            throw new VaultException('Amount must be greater than zero.');
        }

        $min = Decimal::norm((string) ($config['min'] ?? '0'));
        if (Decimal::cmp($amount, $min) < 0) {
            throw new VaultException("Minimum withdrawal for {$asset} is {$min} {$asset}.");
        }

        $chain = isset($config['chain']) ? Chain::from($config['chain']) : null;
        if ($chain) {
            try {
                $this->validator->assertValid($chain, (string) $data['to_address']);
            } catch (\InvalidArgumentException $e) {
                throw new VaultException($e->getMessage());
            }
        }

        $fee = Decimal::norm((string) ($config['fee'] ?? '0'));
        $total = Decimal::add($amount, $fee);

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = Withdrawal::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $autoEligible = $this->isAutoEligible($user, $amount, $config);

        try {
            $withdrawal = DB::transaction(function () use ($user, $asset, $chain, $config, $amount, $fee, $total, $data, $idempotencyKey, $autoEligible): Withdrawal {
                $withdrawal = Withdrawal::query()->create([
                    'user_id' => $user->id,
                    'asset' => $asset,
                    'chain' => $chain?->value,
                    'rail' => (string) ($config['rail'] ?? 'binance'),
                    'amount' => $amount,
                    'fee' => $fee,
                    'total' => $total,
                    'to_address' => (string) $data['to_address'],
                    'memo' => $data['memo'] ?? null,
                    'status' => $autoEligible ? WithdrawalStatus::PendingAuto : WithdrawalStatus::PendingManual,
                    'approval_type' => $autoEligible ? 'auto' : null,
                    'requested_ip' => request()->ip(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Freeze the funds (throws InsufficientBalanceException).
                $this->balances->lockAmount(
                    $user->id,
                    $asset,
                    $total,
                    $withdrawal,
                    "Withdrawal #{$withdrawal->id} funds locked",
                );

                return $withdrawal;
            });
        } catch (InsufficientBalanceException $e) {
            throw new VaultException($e->getMessage());
        }

        $this->audit->log(null, 'withdrawal.requested', $withdrawal, null, [
            'asset' => $asset, 'amount' => $amount, 'to_address' => $withdrawal->to_address,
        ], "User #{$user->id} requested {$amount} {$asset}");

        $this->dispatchChanged($withdrawal, WithdrawalRequested::class);

        if ($autoEligible) {
            ProcessWithdrawalJob::dispatch($withdrawal->id)->onQueue('withdrawals');
        }

        return $withdrawal;
    }

    // ------------------------------------------------------------- admin actions

    /** Approve a manual withdrawal (admin action, audit logged). */
    public function approve(User $admin, Withdrawal $withdrawal): Withdrawal
    {
        $withdrawal = DB::transaction(function () use ($admin, $withdrawal): Withdrawal {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WithdrawalStatus::PendingManual) {
                throw new VaultException("Withdrawal #{$locked->id} is not awaiting approval (status: {$locked->status->value}).");
            }

            $locked->forceFill([
                'status' => WithdrawalStatus::Approved,
                'approval_type' => 'manual',
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ])->save();

            return $locked;
        });

        $this->audit->log($admin, 'withdrawal.approved', $withdrawal, ['status' => 'pending_manual'], ['status' => 'approved']);

        ProcessWithdrawalJob::dispatch($withdrawal->id)->onQueue('withdrawals');
        $this->dispatchChanged($withdrawal, WithdrawalStatusChanged::class);

        return $withdrawal;
    }

    /** Reject a manual withdrawal — funds are unlocked immediately. */
    public function reject(User $admin, Withdrawal $withdrawal, string $reason): Withdrawal
    {
        $withdrawal = DB::transaction(function () use ($admin, $withdrawal, $reason): Withdrawal {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WithdrawalStatus::PendingManual) {
                throw new VaultException("Withdrawal #{$locked->id} is not awaiting approval (status: {$locked->status->value}).");
            }

            $this->balances->releaseAmount(
                $locked->user_id,
                $locked->asset,
                $locked->total,
                $locked,
                "Withdrawal #{$locked->id} rejected",
            );

            $locked->forceFill([
                'status' => WithdrawalStatus::Rejected,
                'rejected_by' => $admin->id,
                'rejected_at' => now(),
                'reject_reason' => $reason,
            ])->save();

            return $locked;
        });

        $this->audit->log($admin, 'withdrawal.rejected', $withdrawal, ['status' => 'pending_manual'], [
            'status' => 'rejected', 'reason' => $reason,
        ]);

        $this->dispatchChanged($withdrawal, WithdrawalStatusChanged::class);

        return $withdrawal;
    }

    // -------------------------------------------------------------- processing

    /** Send the withdrawal via its rail. Called by ProcessWithdrawalJob. */
    public function process(int $withdrawalId): void
    {
        $withdrawal = Withdrawal::query()->find($withdrawalId);
        if (! $withdrawal) {
            return;
        }

        $started = DB::transaction(function () use ($withdrawal): ?Withdrawal {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, [WithdrawalStatus::PendingAuto, WithdrawalStatus::Approved], true)) {
                return null; // already processing/final — nothing to do
            }

            $locked->forceFill([
                'status' => WithdrawalStatus::Processing,
                'provider' => $locked->rail,
                'attempts' => $locked->attempts + 1,
                'processed_at' => now(),
                'failure_reason' => null,
            ])->save();

            return $locked;
        });

        if (! $started) {
            return;
        }

        $this->dispatchChanged($started, WithdrawalStatusChanged::class);

        try {
            if ($started->rail === 'onchain') {
                $this->sendOnChain($started);
            } else {
                $this->sendViaBinance($started);
            }
        } catch (WithdrawalException|BinanceException $e) {
            $maxAttempts = (int) config('vault.withdrawal.max_attempts', 3);
            if ($e->retryable && $started->attempts < $maxAttempts) {
                throw $e; // let the queue retry with backoff
            }
            $this->markFailed($started, $e->getMessage());
        } catch (\Throwable $e) {
            $maxAttempts = (int) config('vault.withdrawal.max_attempts', 3);
            if ($started->attempts < $maxAttempts) {
                throw $e;
            }
            $this->markFailed($started, $e->getMessage());
        }
    }

    /**
     * Poll the rail for a processing withdrawal and finalize it.
     * Called by CheckWithdrawalStatusJob.
     */
    public function refreshStatus(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status !== WithdrawalStatus::Processing) {
            return;
        }

        try {
            match ($withdrawal->provider) {
                'binance' => $this->refreshBinanceStatus($withdrawal),
                'evm' => $this->refreshEvmStatus($withdrawal),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('Withdrawal status refresh failed', [
                'withdrawal' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Force-fail a withdrawal (queue exhausted, admin action or rail error). */
    public function markFailed(Withdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason): void {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();

            if (! $locked || $locked->status->isFinal()) {
                return;
            }

            $this->balances->releaseAmount(
                $locked->user_id,
                $locked->asset,
                $locked->total,
                $locked,
                "Withdrawal #{$locked->id} failed — funds released",
            );

            $locked->forceFill([
                'status' => WithdrawalStatus::Failed,
                'failure_reason' => $reason,
            ])->save();

            DB::afterCommit(function () use ($locked, $reason): void {
                $this->audit->system('withdrawal.failed', $locked, ['reason' => $reason]);
                event(new WithdrawalFailed($locked));
                event(new WithdrawalStatusChanged($locked));
            });
        });
    }

    private function complete(Withdrawal $withdrawal, ?string $txHash): void
    {
        DB::transaction(function () use ($withdrawal, $txHash): void {
            $locked = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();

            if (! $locked || $locked->status->isFinal()) {
                return;
            }

            $this->balances->settleLock(
                $locked->user_id,
                $locked->asset,
                $locked->total,
                $locked,
                "Withdrawal #{$locked->id} completed",
            );

            $locked->forceFill([
                'status' => WithdrawalStatus::Completed,
                'completed_at' => now(),
                'tx_hash' => $txHash ?? $locked->tx_hash,
                'failure_reason' => null,
            ])->save();

            DB::afterCommit(function () use ($locked): void {
                $this->audit->system('withdrawal.completed', $locked);
                event(new WithdrawalCompleted($locked));
                event(new WithdrawalStatusChanged($locked));
            });
        });
    }

    // -------------------------------------------------------------------- rails

    private function sendViaBinance(Withdrawal $withdrawal): void
    {
        $config = $this->assetConfig($withdrawal->asset);

        $response = $this->binance->withdraw(
            coin: $withdrawal->asset,
            address: $withdrawal->to_address,
            amount: Decimal::prettify($withdrawal->amount),
            network: $config['network'] ?? null,
            addressTag: $withdrawal->memo,
            withdrawOrderId: $this->binanceOrderId($withdrawal),
        );

        $withdrawal->forceFill(['provider_withdraw_id' => $response['id'] ?? null])->save();

        Log::info('Withdrawal submitted to Binance', ['withdrawal' => $withdrawal->id, 'binance_id' => $response['id'] ?? null]);
    }

    private function sendOnChain(Withdrawal $withdrawal): void
    {
        $chain = $withdrawal->chain ?? (isset($this->assetConfig($withdrawal->asset)['chain'])
            ? Chain::from($this->assetConfig($withdrawal->asset)['chain'])
            : null);

        if (! $chain) {
            throw WithdrawalException::permanent('No chain configured for this asset.');
        }
        if (! $this->evmSender->supports($chain)) {
            throw WithdrawalException::permanent("On-chain rail is not implemented for {$chain->label()} yet.");
        }

        $txHash = $this->evmSender->send($chain, $withdrawal->asset, $withdrawal->to_address, Decimal::prettify($withdrawal->amount));

        $withdrawal->forceFill(['tx_hash' => $txHash, 'provider' => 'evm'])->save();

        Log::info('Withdrawal broadcast on-chain', ['withdrawal' => $withdrawal->id, 'tx' => $txHash]);
    }

    private function refreshBinanceStatus(Withdrawal $withdrawal): void
    {
        $record = $this->binance->findWithdrawal($withdrawal->asset, $this->binanceOrderId($withdrawal));
        if (! $record) {
            return;
        }

        if (! empty($record['txId']) && $withdrawal->tx_hash !== $record['txId']) {
            $withdrawal->forceFill(['tx_hash' => $record['txId']])->save();
        }

        $status = (int) ($record['status'] ?? 4);
        $info = (string) ($record['info'] ?? $record['msg'] ?? '');

        match (true) {
            $status === 6 => $this->complete($withdrawal, $record['txId'] ?? null),
            in_array($status, [1, 3, 5], true) => $this->markFailed($withdrawal, "Binance status [{$status}] ".($info !== '' ? ": {$info}" : '')),
            default => null, // 0/2/4 = still in progress
        };
    }

    private function refreshEvmStatus(Withdrawal $withdrawal): void
    {
        if (! $withdrawal->tx_hash || ! $withdrawal->chain) {
            return;
        }

        $receipt = $this->rpc->transactionReceipt($withdrawal->chain, $withdrawal->tx_hash);
        if (! is_array($receipt)) {
            return; // still pending
        }

        if (EvmRpcClient::hexToDec((string) $receipt['status']) === '1') {
            $this->complete($withdrawal, $withdrawal->tx_hash);
        } else {
            $this->markFailed($withdrawal, 'Transaction reverted on-chain (receipt status 0).');
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Auto-approval gate: master switch + per-asset limit + 2FA + verified email. */
    private function isAutoEligible(User $user, string $amount, array $config): bool
    {
        $master = $this->settings->bool('withdrawal.auto_enabled', (bool) config('vault.withdrawal.auto_enabled', true));
        $limit = (string) ($config['auto_limit'] ?? '0');

        return $master
            && Decimal::isPositive(Decimal::norm($limit))
            && Decimal::cmp($amount, Decimal::norm($limit)) <= 0
            && $user->hasTwoFactorEnabled()
            && $user->email_verified_at !== null;
    }

    private function binanceOrderId(Withdrawal $withdrawal): string
    {
        // Stable across retries — Binance treats it as an idempotency key.
        return "vault-wd-{$withdrawal->id}";
    }

    private function dispatchChanged(Withdrawal $withdrawal, string $eventClass): void
    {
        DB::afterCommit(fn () => event(new $eventClass($withdrawal->fresh() ?? $withdrawal)));
    }
}
