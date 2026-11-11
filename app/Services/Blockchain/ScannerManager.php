<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;
use App\Services\Blockchain\Scanners\BitcoinScanner;
use App\Services\Blockchain\Scanners\EvmScanner;
use App\Services\Blockchain\Scanners\TronScanner;
use RuntimeException;

/**
 * Resolves the right scanner implementation for a chain family.
 */
final class ScannerManager
{
    public function driver(Chain $chain): ScannerInterface
    {
        return match ($chain->family()) {
            'evm' => app(EvmScanner::class),
            'bitcoin' => app(BitcoinScanner::class),
            'tron' => app(TronScanner::class),
            default => throw new RuntimeException("No scanner for chain family [{$chain->family()}]."),
        };
    }
}
