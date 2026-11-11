<?php

namespace App\Services\Blockchain;

use App\Enums\Chain;
use App\Exceptions\VaultException;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Creates (or reuses) per-user deposit wallets.
 */
final class WalletService
{
    public function __construct(private readonly HdWalletService $hd)
    {
    }

    /**
     * Get the user's deposit address for a chain, provisioning it on first use.
     *
     * EVM chains share one address per user (ETH/BSC/Polygon) so funds sent to
     * the "wrong" EVM chain are still recoverable.
     */
    public function depositWallet(User $user, Chain $chain): Wallet
    {
        if (! $chain->enabled()) {
            throw new VaultException("Chain [{$chain->value}] is currently disabled.");
        }

        $existing = Wallet::query()
            ->where('user_id', $user->id)
            ->where('chain', $chain->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $chain): Wallet {
            if ($chain->family() === 'evm') {
                $sibling = Wallet::query()
                    ->where('user_id', $user->id)
                    ->whereIn('chain', ['eth', 'bsc', 'polygon'])
                    ->first();

                if ($sibling) {
                    return Wallet::query()->create([
                        'user_id' => $user->id,
                        'chain' => $chain->value,
                        'address' => $sibling->address,
                        'derivation_path' => $sibling->derivation_path,
                        'address_index' => $sibling->address_index,
                        'label' => 'Shared EVM address',
                    ]);
                }
            }

            $index = $this->hd->allocateIndex($chain);
            $derived = $this->hd->derive($chain, $index);

            return Wallet::query()->create([
                'user_id' => $user->id,
                'chain' => $chain->value,
                'address' => $derived->address,
                'derivation_path' => $derived->path,
                'address_index' => $index,
            ]);
        });
    }
}
