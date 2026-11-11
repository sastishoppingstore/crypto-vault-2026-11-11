<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;

/**
 * Sends withdrawals from the platform hot wallet on-chain.
 * Returns the broadcast transaction hash.
 */
interface OnChainSenderInterface
{
    public function supports(Chain $chain): bool;

    /**
     * @param  string  $asset  native symbol or token symbol (e.g. USDT)
     * @return string transaction hash
     */
    public function send(Chain $chain, string $asset, string $to, string $amount): string;
}
