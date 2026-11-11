<?php

/*
|--------------------------------------------------------------------------
| Exchange integration (Binance native + CCXT for everything else)
|--------------------------------------------------------------------------
| API credentials themselves are NOT stored in config/env — they live
| encrypted in the `api_keys` table and are managed from the admin panel.
| Only non-secret behaviour is configured here.
*/

return [

    'default' => env('EXCHANGE_DEFAULT', 'binance'),

    'binance' => [
        // Use https://testnet.binance.vision for staging / integration tests.
        'testnet' => (bool) env('BINANCE_TESTNET', false),
        'base_url' => env('BINANCE_BASE_URL', 'https://api.binance.com'),
        'testnet_url' => env('BINANCE_TESTNET_URL', 'https://testnet.binance.vision'),
        'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
        'timeout' => (int) env('BINANCE_TIMEOUT', 15),
        'retry' => [
            'times' => 3,
            'sleep_ms' => 500,
        ],
        // Coins synced into the treasury ledger by SyncExchangeDepositsJob.
        'treasury_coins' => ['USDT', 'BTC', 'ETH', 'BNB', 'TRX'],
    ],

    /*
    |--------------------------------------------------------------------------
    | CCXT (multi-exchange)
    |--------------------------------------------------------------------------
    | Used as a fallback/secondary rail. Exchange class name is resolved by
    | ccxt (e.g. binance, kraken, okx). Credentials come from `api_keys`.
    */
    'ccxt' => [
        'enabled' => (bool) env('CCXT_ENABLED', false),
        'exchanges' => ['binance', 'kraken', 'okx', 'bybit', 'kucoin'],
    ],
];
