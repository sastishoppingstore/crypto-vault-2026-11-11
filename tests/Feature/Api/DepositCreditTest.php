<?php

namespace Tests\Feature\Api;

use App\Enums\Chain;
use App\Enums\DepositStatus;
use App\Models\Balance;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VaultSecret;
use App\Services\Blockchain\IncomingTransfer;
use App\Services\Blockchain\WalletService;
use App\Services\DepositService;
use App\Services\Security\SettingsService;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end deposit pipeline on a local SQLite database:
 *   HD provisioning -> scanner transfer -> confirmation gate ->
 *   idempotent credit -> reorg handling.
 */
final class DepositCreditTest extends TestCase
{
    use RefreshDatabase;

    private const VECTOR_MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

    /** Derived at m/44'/60'/0'/0/0 from the vector mnemonic (BIP44). */
    private const VECTOR_EVM_ADDRESS = '0x9858EfFD232B4033E47d90003D41EC34EcaEda94';

    protected function setUp(): void
    {
        parent::setUp();

        config(['blockchain.chains.eth.enabled' => true]);

        // The encrypted seed that WalletService/HdWalletService derive from.
        VaultSecret::put('hd_seed_mainnet', self::VECTOR_MNEMONIC);

        app(SettingsService::class)->set('deposits.confirmations', ['eth' => 12], 'deposits');
    }

    public function test_deposit_wallet_is_derived_from_the_encrypted_seed(): void
    {
        $user = User::factory()->create();

        $wallet = app(WalletService::class)->depositWallet($user, Chain::Eth);

        $this->assertSame(self::VECTOR_EVM_ADDRESS, $wallet->address);
        $this->assertSame("44'/60'/0'/0/0", $wallet->derivation_path);
        $this->assertSame(0, $wallet->address_index);
    }

    public function test_confirmed_deposit_is_credited_exactly_once(): void
    {
        $user = $this->userWithWallet();
        $service = app(DepositService::class);

        $transfer = $this->transfer(confirmations: 12);

        $first = $service->detect($transfer);
        $this->assertNotNull($first);
        $this->assertSame(DepositStatus::Credited, $first->status);
        $this->assertAvailable($user, 'ETH', '1.5');

        // Redelivery (scanner retry or duplicate webhook) must be a no-op.
        $second = $service->detect($transfer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(1, Transaction::query()->where('user_id', $user->id)->count());
        $this->assertAvailable($user, 'ETH', '1.5');
    }

    public function test_deposit_is_credited_only_after_required_confirmations(): void
    {
        $user = $this->userWithWallet();
        $service = app(DepositService::class);

        $deposit = $service->detect($this->transfer(confirmations: 3));

        $this->assertNotNull($deposit);
        $this->assertSame(DepositStatus::Detected, $deposit->status);
        $this->assertNull(Deposit::query()->find($deposit->id)->credited_at);
        $this->assertAvailable($user, 'ETH', '0');

        // A scanner pass sees the transfer is mined but still short of 12.
        $this->assertSame(0, $service->refresh(Chain::Eth, 102));
        $this->assertSame(DepositStatus::Confirming, $deposit->refresh()->status);

        // The chain advanced: block 100 now has 12 confirmations at height 111.
        $credited = $service->refresh(Chain::Eth, 111);

        $this->assertSame(1, $credited);
        $this->assertSame(DepositStatus::Credited, $deposit->refresh()->status);
        $this->assertAvailable($user, 'ETH', '1.5');
    }

    public function test_reorged_deposit_is_marked_orphaned_and_never_credited(): void
    {
        $user = $this->userWithWallet();
        $service = app(DepositService::class);

        $service->detect($this->transfer(confirmations: 0, blockNumber: 100));

        // Same tx detected again without a block -> reorged out.
        $orphaned = $service->detect($this->transfer(confirmations: 0, blockNumber: null));

        $this->assertSame(DepositStatus::Orphaned, $orphaned->status);

        // Even if it somehow gets confirmations later, orphaned deposits stay out.
        $service->credit($orphaned->refresh());
        $this->assertSame(DepositStatus::Orphaned, $orphaned->refresh()->status);
        $this->assertAvailable($user, 'ETH', '0');
    }

    public function test_transfer_to_an_unknown_address_is_ignored(): void
    {
        $this->userWithWallet();

        $unknown = $this->transfer(toAddress: '0x000000000000000000000000000000000000dEaD');

        $this->assertNull(app(DepositService::class)->detect($unknown));
        $this->assertSame(0, Deposit::query()->count());
    }

    // ------------------------------------------------------------------ helpers

    private function userWithWallet(): User
    {
        $user = User::factory()->create();

        app(WalletService::class)->depositWallet($user, Chain::Eth);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function transfer(
        int $confirmations = 12,
        ?int $blockNumber = 100,
        string $toAddress = self::VECTOR_EVM_ADDRESS,
    ): IncomingTransfer {
        return new IncomingTransfer(
            chain: Chain::Eth,
            asset: 'ETH',
            txHash: '0xabc123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd',
            logIndex: 0,
            fromAddress: '0x1111111111111111111111111111111111111111',
            toAddress: $toAddress,
            amount: '1.5',
            blockNumber: $blockNumber,
            confirmations: $confirmations,
        );
    }

    /** Compare as exact 18-decimal strings (SQLite numeric columns return floats). */
    private function assertAvailable(User $user, string $asset, string $expected): void
    {
        $balance = Balance::query()
            ->where('user_id', $user->id)
            ->where('asset', $asset)
            ->first();

        $this->assertSame(
            Decimal::norm($expected),
            Decimal::norm((string) ($balance?->available ?? '0')),
        );
    }
}
