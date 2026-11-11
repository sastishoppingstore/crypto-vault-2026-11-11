<?php

namespace App\Console\Commands;

use App\Services\Security\EncryptionService;
use Illuminate\Console\Command;

class VaultKeyCommand extends Command
{
    protected $signature = 'vault:key';

    protected $description = 'Generate a new VAULT_MASTER_KEY and print it (store it in .env + offline backup)';

    public function handle(): int
    {
        $key = EncryptionService::generateKey();

        $this->newLine();
        $this->components->info('Add this line to your .env file (and store a copy in your offline backup):');
        $this->line("VAULT_MASTER_KEY={$key}");
        $this->newLine();
        $this->components->warn('Losing this key means the HD seed, hot wallet keys and exchange API keys are unrecoverable.');

        return self::SUCCESS;
    }
}
