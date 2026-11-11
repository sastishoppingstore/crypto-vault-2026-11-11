<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one withdrawal via its rail (Binance / on-chain hot wallet).
 *
 * Unique per withdrawal id (no double dispatch inside the unique window) and
 * the service itself re-checks the status under a row lock, so retries and
 * duplicate dispatches can never send funds twice.
 */
class ProcessWithdrawalJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $withdrawalId)
    {
        $this->onQueue('withdrawals');
    }

    public function uniqueId(): string
    {
        return 'withdrawal-'.$this->withdrawalId;
    }

    public function handle(WithdrawalService $service): void
    {
        $service->process($this->withdrawalId);
    }

    /** Queue exhausted — fail the withdrawal and unlock the funds. */
    public function failed(Throwable $e): void
    {
        $withdrawal = Withdrawal::query()->find($this->withdrawalId);
        if ($withdrawal) {
            app(WithdrawalService::class)->markFailed($withdrawal, 'Processing attempts exhausted: '.$e->getMessage());
        }
    }
}
