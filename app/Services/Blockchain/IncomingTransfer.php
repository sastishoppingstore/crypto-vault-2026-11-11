<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;

/**
 * Normalized representation of an incoming on-chain transfer,
 * produced by every scanner and consumed by DepositService.
 */
final readonly class IncomingTransfer
{
    public function __construct(
        public Chain $chain,
        public string $asset,
        public string $txHash,
        public int $logIndex,
        public ?string $fromAddress,
        public string $toAddress,
        public string $amount,
        public ?int $blockNumber,
        public int $confirmations,
        public array $raw = [],
    ) {
    }
}
