<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Events\BalanceUpdated;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Balance;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place balances are ever mutated.
 *
 *  - every mutation runs inside a DB transaction with a row lock
 *    (SELECT ... FOR UPDATE) so concurrent requests cannot overspend;
 *  - every mutation appends one ledger row (transactions table);
 *  - BalanceUpdated is broadcast to the user's private channel after commit.
 */
final class BalanceService
{
    public function available(int $userId, string $asset): string
    {
        $balance = Balance::query()->where('user_id', $userId)->where('asset', $asset)->first();

        return $balance?->available ?? '0';
    }

    /** Credit available balance (deposits, refunds, corrections). */
    public function credit(
        int $userId,
        string $asset,
        string $amount,
        TransactionType $type,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
    ): Transaction {
        return $this->apply($userId, $asset, [
            'available' => Decimal::norm($amount),
        ], $type, $reference, $description, $meta);
    }

    /** Freeze funds for a pending withdrawal: available -> locked. */
    public function lockAmount(
        int $userId,
        string $asset,
        string $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
    ): Transaction {
        $amount = Decimal::norm($amount);

        return $this->apply($userId, $asset, [
            'available' => Decimal::neg($amount),
            'locked' => $amount,
        ], TransactionType::WithdrawalLock, $reference, $description, $meta);
    }

    /** Return frozen funds (rejected/failed withdrawal): locked -> available. */
    public function releaseAmount(
        int $userId,
        string $asset,
        string $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
    ): Transaction {
        $amount = Decimal::norm($amount);

        return $this->apply($userId, $asset, [
            'available' => $amount,
            'locked' => Decimal::neg($amount),
        ], TransactionType::WithdrawalUnlock, $reference, $description, $meta);
    }

    /** Settle a completed withdrawal: locked funds leave the system. */
    public function settleLock(
        int $userId,
        string $asset,
        string $amount,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
    ): Transaction {
        return $this->apply($userId, $asset, [
            'locked' => Decimal::neg(Decimal::norm($amount)),
        ], TransactionType::WithdrawalDebit, $reference, $description, $meta);
    }

    /** Manual admin adjustment; $signedDelta may be negative. */
    public function adjust(
        User $user,
        string $asset,
        string $signedDelta,
        string $reason,
        ?User $admin = null,
    ): Transaction {
        return $this->apply($user->id, $asset, [
            'available' => Decimal::norm($signedDelta),
        ], TransactionType::AdminAdjustment, null, $reason, [
            'admin_id' => $admin?->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array{available?: string, locked?: string}  $deltas
     */
    private function apply(
        int $userId,
        string $asset,
        array $deltas,
        TransactionType $type,
        ?Model $reference,
        ?string $description,
        array $meta,
    ): Transaction {
        $availableDelta = Decimal::norm($deltas['available'] ?? '0');
        $lockedDelta = Decimal::norm($deltas['locked'] ?? '0');

        $transaction = DB::transaction(function () use (
            $userId, $asset, $availableDelta, $lockedDelta, $type, $reference, $description, $meta
        ): Transaction {
            $balance = Balance::query()->firstOrCreate(
                ['user_id' => $userId, 'asset' => $asset],
                ['available' => '0', 'locked' => '0'],
            );

            // Re-read with a row lock for a consistent check-and-set.
            $balance = Balance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

            $newAvailable = Decimal::add($balance->available, $availableDelta);
            $newLocked = Decimal::add($balance->locked, $lockedDelta);

            if (bccomp($newAvailable, '0', Decimal::SCALE) < 0 || bccomp($newLocked, '0', Decimal::SCALE) < 0) {
                throw new InsufficientBalanceException($asset, $balance->available, Decimal::prettify(Decimal::neg($availableDelta)));
            }

            $balance->forceFill([
                'available' => $newAvailable,
                'locked' => $newLocked,
            ])->save();

            return Transaction::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'asset' => $asset,
                'amount' => $availableDelta,
                'balance_after' => $newAvailable,
                'reference_type' => $reference ? $reference->getMorphClass() : null,
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'meta' => $meta + [
                    'locked_delta' => $lockedDelta,
                    'locked_after' => $newLocked,
                ],
            ]);
        });

        // Real-time push to the user's dashboard (only after the commit).
        DB::afterCommit(function () use ($userId, $asset): void {
            $balance = Balance::query()->where('user_id', $userId)->where('asset', $asset)->first();
            if ($balance) {
                event(new BalanceUpdated($userId, $asset, $balance->available, $balance->locked));
            }
        });

        return $transaction;
    }
}
