<?php

namespace Tests\Feature\Api;

use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\VaultException;
use App\Models\ApiKey;
use App\Models\Balance;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\BalanceService;
use App\Services\Security\SettingsService;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Full withdrawal lifecycle against a faked Binance Spot (testnet) API:
 *
 *   request -> pending_manual (funds locked) -> approve -> processing
 *           -> status refresh -> completed (funds settled)
 *   request -> pending_manual -> reject                  -> funds unlocked
 *   request -> approve -> permanent Binance error        -> failed + unlocked
 */
final class WithdrawalFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private WithdrawalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // All Binance traffic goes through Http::fake() in the test bodies.
        config(['exchanges.binance.testnet' => true]);

        $settings = app(SettingsService::class);
        $settings->set('withdrawal.auto_enabled', true, 'withdrawal');
        $settings->set('withdrawal.assets', [
            'USDT' => [
                'enabled' => true,
                'rail' => 'binance',
                'min' => '10',
                'fee' => '1',
                'auto_limit' => '0', // force the manual approval path
            ],
        ], 'withdrawal');

        ApiKey::query()->create([
            'name' => 'test',
            'exchange' => 'binance',
            'key' => 'test-api-key',
            'secret' => 'test-api-secret',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin']);

        app(BalanceService::class)->credit(
            $this->user->id,
            'USDT',
            '100',
            TransactionType::AdminAdjustment,
        );

        $this->service = app(WithdrawalService::class);
    }

    public function test_manual_withdrawal_locks_funds_and_is_idempotent(): void
    {
        $withdrawal = $this->requestWithdrawal('50', 'idem-1');

        $this->assertSame(WithdrawalStatus::PendingManual, $withdrawal->status);
        $this->assertFunds('49', '51');

        // Same idempotency key -> same withdrawal, no second lock.
        $repeat = $this->requestWithdrawal('50', 'idem-1');

        $this->assertSame($withdrawal->id, $repeat->id);
        $this->assertSame(1, Withdrawal::query()->count());
        $this->assertFunds('49', '51');
    }

    public function test_approved_withdrawal_is_sent_via_binance_then_completed(): void
    {
        Http::fake([
            'testnet.binance.vision/sapi/v1/capital/withdraw/apply*' => Http::response(['id' => 'wd-1'], 200),
            'testnet.binance.vision/sapi/v1/capital/withdraw/history*' => Http::response([[
                'id' => 'wd-1',
                'txId' => '0xdeadbeef',
                'status' => 6,
                'info' => 'Completed',
            ]], 200),
        ]);

        $withdrawal = $this->requestWithdrawal('50', 'idem-2');

        // approve() dispatches ProcessWithdrawalJob; the sync queue runs it now.
        $this->service->approve($this->admin, $withdrawal);
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->status);
        $this->assertSame('wd-1', $withdrawal->provider_withdraw_id);
        $this->assertNotNull($withdrawal->processed_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sapi/v1/capital/withdraw/apply')
            && $request->hasHeader('X-MBX-APIKEY', 'test-api-key'));

        // Status poll: Binance reports status 6 = completed.
        $this->service->refreshStatus($withdrawal);
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertSame('0xdeadbeef', $withdrawal->tx_hash);
        $this->assertNotNull($withdrawal->completed_at);
        $this->assertFunds('49', '0');
    }

    public function test_rejected_withdrawal_unlocks_funds_and_cannot_be_approved_again(): void
    {
        $withdrawal = $this->requestWithdrawal('20', null);

        $this->assertFunds('79', '21');

        $this->service->reject($this->admin, $withdrawal, 'KYC mismatch');
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::Rejected, $withdrawal->status);
        $this->assertSame('KYC mismatch', $withdrawal->reject_reason);
        $this->assertSame($this->admin->id, $withdrawal->rejected_by);
        $this->assertFunds('100', '0');

        try {
            $this->service->approve($this->admin, $withdrawal);
            $this->fail('Approving a rejected withdrawal must throw.');
        } catch (VaultException $e) {
            $this->assertStringContainsString('not awaiting approval', $e->getMessage());
        }
    }

    public function test_permanent_binance_error_fails_the_withdrawal_and_unlocks_funds(): void
    {
        Http::fake([
            'testnet.binance.vision/sapi/v1/capital/withdraw/apply*' => Http::response([
                'code' => -1001,
                'msg' => 'Internal error; unable to process your request',
            ], 400),
        ]);

        $withdrawal = $this->requestWithdrawal('50', null);

        $this->service->approve($this->admin, $withdrawal);
        $withdrawal->refresh();

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->status);
        $this->assertStringContainsString('Internal error', (string) $withdrawal->failure_reason);
        $this->assertFunds('100', '0');
    }

    // ------------------------------------------------------------------ helpers

    /** Compare balances as exact 18-decimal strings (SQLite returns floats). */
    private function assertFunds(string $available, string $locked): void
    {
        $balance = $this->balance();

        $this->assertSame(\App\Support\Decimal::norm($available), \App\Support\Decimal::norm((string) $balance->available));
        $this->assertSame(\App\Support\Decimal::norm($locked), \App\Support\Decimal::norm((string) $balance->locked));
    }

    private function requestWithdrawal(string $amount, ?string $idempotencyKey): Withdrawal
    {
        return $this->service->request($this->user, [
            'asset' => 'USDT',
            'amount' => $amount,
            'to_address' => '0x3535353535353535353535353535353535353535',
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function balance(): Balance
    {
        return Balance::query()
            ->where('user_id', $this->user->id)
            ->where('asset', 'USDT')
            ->firstOrFail();
    }
}
