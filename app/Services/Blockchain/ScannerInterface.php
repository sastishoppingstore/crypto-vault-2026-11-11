<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;

/**
 * A chain deposit scanner. Implementations may re-report transfers they have
 * already seen — DepositService dedupes on (chain, tx_hash, log_index, asset).
 */
interface ScannerInterface
{
    /**
     * Detect transfers addressed to any of our active wallets on $chain.
     *
     * @return iterable<IncomingTransfer>
     */
    public function scan(Chain $chain): iterable;

    /** Current chain height (block number). */
    public function latestHeight(Chain $chain): int;
}
