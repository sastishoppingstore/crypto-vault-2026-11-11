<?php

namespace App\Console\Commands;

use App\Enums\Chain;
use App\Jobs\ScanChainDepositsJob;
use Illuminate\Console\Command;

/** Manual deposit scan trigger (normally runs via the scheduler). */
class ScanDepositsCommand extends Command
{
    protected $signature = 'vault:scan-deposits {--chain=* : Chains to scan (default: all enabled)}';

    protected $description = 'Scan enabled chains for incoming deposits and credit confirmed transfers';

    public function handle(): int
    {
        $requested = (array) $this->option('chain');

        $chains = $requested !== []
            ? array_map(fn (string $c) => Chain::from(strtolower($c)), $requested)
            : Chain::enabledChains();

        if ($chains === []) {
            $this->components->warn('No chains enabled. Set BTC_ENABLED/ETH_ENABLED/... in .env.');

            return self::SUCCESS;
        }

        foreach ($chains as $chain) {
            $this->components->info("Scanning {$chain->label()}...");
            try {
                ScanChainDepositsJob::dispatchSync($chain);
                $this->components->info("{$chain->value} scan finished.");
            } catch (\Throwable $e) {
                $this->components->error("{$chain->value}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
