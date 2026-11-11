<?php

namespace App\Console\Commands;

use App\Models\AdminLog;
use Illuminate\Console\Command;

/** Purges audit log entries older than the configured retention. */
class PruneLogsCommand extends Command
{
    protected $signature = 'vault:prune-logs';

    protected $description = 'Delete audit logs older than vault.audit.log_retention_days';

    public function handle(): int
    {
        $days = (int) config('vault.audit.log_retention_days', 365);

        $deleted = AdminLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->components->info("Pruned {$deleted} audit log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
