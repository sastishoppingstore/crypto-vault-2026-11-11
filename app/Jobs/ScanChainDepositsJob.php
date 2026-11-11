<?php

namespace App\Jobs;

use App\Enums\Chain;
use App\Services\Blockchain\ScannerManager;
use App\Services\DepositService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scans one chain for incoming deposits, records new transfers and credits
 * anything that reached its confirmation threshold.
 *
 * Scheduled every minute per enabled chain (see bootstrap/app.php).
 */
class ScanChainDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 240;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly Chain $chain)
    {
        $this->onQueue('deposits');
    }

    public function handle(ScannerManager $manager, DepositService $deposits): void
    {
        $scanner = $manager->driver($this->chain);
        $latest = $scanner->latestHeight($this->chain);

        $detected = 0;
        foreach ($scanner->scan($this->chain) as $transfer) {
            if ($deposits->detect($transfer) !== null) {
                $detected++;
            }
        }

        $credited = $deposits->refresh($this->chain, $latest);

        Log::info('Chain scan finished', [
            'chain' => $this->chain->value,
            'height' => $latest,
            'detected' => $detected,
            'credited' => $credited,
        ]);
    }
}
