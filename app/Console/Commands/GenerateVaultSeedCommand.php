<?php

namespace App\Console\Commands;

use App\Enums\Chain;
use App\Models\VaultSecret;
use App\Services\Blockchain\HdWalletService;
use Illuminate\Console\Command;

/**
 * Generates the platform HD seed (BIP39 mnemonic), stores it AES-256-GCM
 * encrypted in vault_secrets and prints it ONCE for offline backup.
 *
 * Run this on an air-gapped machine if you can — the mnemonic controls all
 * user deposit addresses.
 */
class GenerateVaultSeedCommand extends Command
{
    protected $signature = 'vault:generate-seed {--words=24 : 12 or 24} {--force : Overwrite an existing seed}';

    protected $description = 'Generate and store the encrypted HD wallet seed';

    public function handle(HdWalletService $hd): int
    {
        $name = (string) config('blockchain.seed_secret', 'hd_seed_mainnet');
        $words = (int) $this->option('words');

        if (! in_array($words, [12, 24], true)) {
            $this->components->error('--words must be 12 or 24.');

            return self::FAILURE;
        }

        if (VaultSecret::query()->where('name', $name)->exists() && ! $this->option('force')) {
            $this->components->error("Seed [{$name}] already exists. Use --force to overwrite (DANGEROUS: old addresses stay valid but their keys are lost).");

            return self::FAILURE;
        }

        try {
            $mnemonic = $hd->generateMnemonic($words === 24 ? 256 : 128);

            // Verify the mnemonic round-trips before persisting anything.
            $master = $hd->masterKey($mnemonic);
            if ($master->getDepth() !== 0) {
                throw new \RuntimeException('Seed verification failed.');
            }

            VaultSecret::put($name, $mnemonic, ['words' => $words]);
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->warn('WRITE THIS MNEMONIC DOWN OFFLINE. It is the only way to recover user deposit addresses. It is not shown again.');
        $this->newLine();
        $this->line('  '.$mnemonic);
        $this->newLine();

        $this->components->info('Sample first addresses (index 0), for verification:');
        foreach ([Chain::Btc, Chain::Eth, Chain::Tron] as $chain) {
            try {
                $derived = $hd->derive($chain, 0);
                $this->line(sprintf('  %-8s %s', strtoupper($chain->value), $derived->address));
            } catch (\Throwable $e) {
                $this->line("  {$chain->value}: skipped ({$e->getMessage()})");
            }
        }

        $this->newLine();
        $this->components->info("Seed stored encrypted as vault_secret [{$name}]. Keep VAULT_MASTER_KEY safe too — both are required.");

        return self::SUCCESS;
    }
}
