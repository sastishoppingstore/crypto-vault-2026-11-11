<?php

/*
|--------------------------------------------------------------------------
| Blockchain configuration
|--------------------------------------------------------------------------
| Every chain the vault can generate deposit addresses for, scan for
| incoming transfers and (optionally) send withdrawals on.
|
| Derivation paths follow BIP44/BIP84:
|   BTC      m/84'/0'/0'/0/i   (native segwit, coin type 0, mainnet)
|   ETH/EVMs m/44'/60'/0'/0/i  (shared address across all EVM chains)
|   TRON     m/44'/195'/0'/0/i
|
| In testnet mode (BTC_TESTNET / chain testnet flag) coin type 1 is used for
| BTC. EVM/Tron testnets keep the mainnet derivation path (standard practice).
*/

return [

    // Name of the vault_secrets row that stores the encrypted BIP39 mnemonic.
    'seed_secret' => env('VAULT_SEED_NAME', 'hd_seed_mainnet'),
    'seed_passphrase' => env('VAULT_SEED_PASSPHRASE', ''),

    'chains' => [

        'btc' => [
            'enabled' => (bool) env('BTC_ENABLED', false),
            'family' => 'bitcoin',
            'label' => 'Bitcoin',
            'asset' => 'BTC',
            'decimals' => 8,
            'confirmations' => (int) env('BTC_CONFIRMATIONS', 3),
            'derivation_path' => env('BTC_DERIVATION_PATH', "84'/0'/0'/0"),
            'testnet' => (bool) env('BTC_TESTNET', false),
            'api_url' => env('BTC_API_URL', 'https://mempool.space/api'),
            'explorer_tx_url' => env('BTC_EXPLORER_TX_URL', 'https://mempool.space/tx/'),
            'tokens' => [],
        ],

        'eth' => [
            'enabled' => (bool) env('ETH_ENABLED', false),
            'family' => 'evm',
            'label' => 'Ethereum',
            'asset' => 'ETH',
            'decimals' => 18,
            'chain_id' => 1,
            'confirmations' => (int) env('ETH_CONFIRMATIONS', 12),
            'derivation_path' => "44'/60'/0'/0",
            'rpc_url' => env('ETH_RPC_URL', 'https://ethereum-rpc.publicnode.com'),
            'explorer_tx_url' => env('ETH_EXPLORER_TX_URL', 'https://etherscan.io/tx/'),
            'tokens' => [
                ['symbol' => 'USDT', 'address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6],
                ['symbol' => 'USDC', 'address' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6],
                ['symbol' => 'DAI', 'address' => '0x6B175474E89094C44Da98b954EedeAC495271d0F', 'decimals' => 18],
            ],
        ],

        'bsc' => [
            'enabled' => (bool) env('BSC_ENABLED', false),
            'family' => 'evm',
            'label' => 'BNB Smart Chain',
            'asset' => 'BNB',
            'decimals' => 18,
            'chain_id' => 56,
            'confirmations' => (int) env('BSC_CONFIRMATIONS', 15),
            'derivation_path' => "44'/60'/0'/0",
            'rpc_url' => env('BSC_RPC_URL', 'https://bsc-rpc.publicnode.com'),
            'explorer_tx_url' => env('BSC_EXPLORER_TX_URL', 'https://bscscan.com/tx/'),
            'tokens' => [
                ['symbol' => 'USDT', 'address' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18],
                ['symbol' => 'USDC', 'address' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18],
            ],
        ],

        'polygon' => [
            'enabled' => (bool) env('POLYGON_ENABLED', false),
            'family' => 'evm',
            'label' => 'Polygon',
            'asset' => 'POL',
            'decimals' => 18,
            'chain_id' => 137,
            'confirmations' => (int) env('POLYGON_CONFIRMATIONS', 128),
            'derivation_path' => "44'/60'/0'/0",
            'rpc_url' => env('POLYGON_RPC_URL', 'https://polygon-bor-rpc.publicnode.com'),
            'explorer_tx_url' => env('POLYGON_EXPLORER_TX_URL', 'https://polygonscan.com/tx/'),
            'tokens' => [
                ['symbol' => 'USDT', 'address' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'decimals' => 6],
                ['symbol' => 'USDC', 'address' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'decimals' => 6],
            ],
        ],

        'tron' => [
            'enabled' => (bool) env('TRON_ENABLED', false),
            'family' => 'tron',
            'label' => 'Tron',
            'asset' => 'TRX',
            'decimals' => 6,
            'confirmations' => (int) env('TRON_CONFIRMATIONS', 19),
            'derivation_path' => "44'/195'/0'/0",
            'api_url' => env('TRON_API_URL', 'https://api.trongrid.io'),
            'api_key' => env('TRON_API_KEY'),
            'explorer_tx_url' => env('TRON_EXPLORER_TX_URL', 'https://tronscan.org/#/transaction/'),
            'tokens' => [
                ['symbol' => 'USDT', 'address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'decimals' => 6],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Address scanning
    |--------------------------------------------------------------------------
    */
    'scanning' => [
        // Max blocks scanned per chain per run (keeps jobs fast).
        'max_blocks_per_run' => (int) env('SCAN_MAX_BLOCKS_PER_RUN', 300),
        // How far behind the chain head the cursor stays (reorg protection).
        'reorg_safety_blocks' => (int) env('SCAN_REORG_SAFETY_BLOCKS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hot wallet
    |--------------------------------------------------------------------------
    | Private keys are stored encrypted in vault_secrets under
    | "hot_wallet.{key}" (e.g. hot_wallet.evm). Only keep a small operational
    | amount here — cold storage holds the bulk of the funds.
    */
    'hot_wallet' => [
        'evm_secret_name' => 'hot_wallet.evm',
    ],
];
