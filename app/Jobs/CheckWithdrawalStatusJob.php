<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls the rail for every withdrawal in "processing" state and finalizes
 * them (completed / failed). Also flags withdrawals stuck for too long so
 * ops can investigate (they are NOT auto-failed — manual review).
 */
class CheckWithdrawalStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('withdrawals');
    }

    public function handle(WithdrawalService $service): void
    {
        Withdrawal::query()
            ->processing()
            ->chunkById(100, function ($withdrawals) use ($service): void {
                foreach ($withdrawals as $withdrawal) {
                    $service->refreshStatus($withdrawal);
                }
            });

        $stuckAfter = (int) config('vault.withdrawal.stuck_after_minutes', 120);

        Withdrawal::query()
            ->processing()
            ->where('processed_at', '<', now()->subMinutes($stuckAfter))
            ->limit(50)
            ->get()
            ->each(function (Withdrawal $withdrawal) use ($stuckAfter): void {
                Log::warning('Withdrawal stuck in processing', [
                    'withdrawal' => $withdrawal->id,
                    'provider' => $withdrawal->provider,
                    'provider_withdraw_id' => $withdrawal->provider_withdraw_id,
                    'tx_hash' => $withdrawal->tx_hash,
                    'minutes' => $stuckAfter,
                ]);
            });
    }
}
