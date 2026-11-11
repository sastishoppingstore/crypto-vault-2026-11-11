<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound webhooks (deposit notifications)
    |--------------------------------------------------------------------------
    | Every provider entry needs a shared secret (HMAC-SHA256 of the raw
    | request body, hex encoded, optionally "sha256=" prefixed).
    |
    | NOTE: on-chain polling (ScanChainDepositsJob) is the safety net — webhooks
    | only make detection faster. Even a forged webhook cannot double credit.
    */

    'providers' => [

        // Alchemy "Address Activity" webhooks (EVM chains).
        'alchemy' => [
            'secret' => env('ALCHEMY_WEBHOOK_SIGNING_KEY'),
            'signature_header' => 'X-Alchemy-Signature',
            'allowed_ips' => [],
            // Alchemy network id => vault chain
            'network_map' => [
                'ETH_MAINNET' => 'eth',
                'BNB_MAINNET' => 'bsc',
                'MATIC_MAINNET' => 'polygon',
            ],
        ],

        // Generic normalized payload provider (custom indexer / internal).
        // Expected JSON:
        //   { chain, asset, tx_hash, log_index, from, to, amount, block_number }
        'custom' => [
            'secret' => env('WEBHOOK_CUSTOM_SECRET'),
            'signature_header' => 'X-Signature',
            'allowed_ips' => [],
        ],
    ],
];
