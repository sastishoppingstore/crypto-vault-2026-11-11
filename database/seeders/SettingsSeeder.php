<?php

namespace Database\Seeders;

use App\Services\Security\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Seeds the runtime settings consumed by WithdrawalService and DepositService.
 *
 * Safe to re-run: values are upserted by key (SettingsService::set).
 *
 * Withdrawal asset entries require:
 *   enabled    bool            — kill switch per asset
 *   rail       binance|onchain — payout rail (onchain = platform hot wallet, EVM only)
 *   min        decimal string  — minimum user-facing amount
 *   fee        decimal string  — flat fee charged on top of the amount
 *   auto_limit decimal string  — amounts <= limit are auto-queued (if master switch on)
 *   chain      optional        — required for onchain rail (address validation)
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);

        // ------------------------------------------------------- master switch
        $settings->set(
            'withdrawal.auto_enabled',
            true,
            'withdrawal',
            'Auto-process withdrawals at or below the per-asset auto_limit (else all manual).',
        );

        // --------------------------------------------------- withdrawal assets
        $settings->set('withdrawal.assets', [
            'USDT' => ['enabled' => true, 'rail' => 'binance', 'min' => '10', 'fee' => '1', 'auto_limit' => '500'],
            'BTC' => ['enabled' => true, 'rail' => 'binance', 'min' => '0.001', 'fee' => '0.0002', 'auto_limit' => '0.05'],
            'ETH' => ['enabled' => true, 'rail' => 'binance', 'min' => '0.01', 'fee' => '0.002', 'auto_limit' => '1'],
            'BNB' => ['enabled' => true, 'rail' => 'binance', 'min' => '0.05', 'fee' => '0.002', 'auto_limit' => '5'],
            'TRX' => ['enabled' => true, 'rail' => 'binance', 'min' => '50', 'fee' => '2', 'auto_limit' => '10000'],

            // On-chain rail example (platform hot wallet — EVM chains only for now):
            // 'USDT_ETH' => ['enabled' => false, 'rail' => 'onchain', 'chain' => 'eth',
            //                'min' => '20', 'fee' => '2', 'auto_limit' => '0'],
        ], 'withdrawal', 'Per-asset withdrawal policy (rail, min, fee, auto_limit).');

        // ------------------------------------------------ deposit confirmations
        // Required confirmations before a detected deposit is credited.
        $settings->set('deposits.confirmations', [
            'btc' => 3,
            'eth' => 12,
            'bsc' => 15,
            'polygon' => 128,
            'tron' => 19,
        ], 'deposits', 'Required confirmations per chain before crediting a deposit.');
    }
}
