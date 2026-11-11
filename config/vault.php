<?php

/*
|--------------------------------------------------------------------------
| Vault security configuration
|--------------------------------------------------------------------------
*/

return [

    /*
    | Key provider for AES-256-GCM envelope encryption.
    |   env : key derived from VAULT_MASTER_KEY (default)
    |   hsm : plug an external HSM/KMS provider (see App\Services\Security\Hsm)
    */
    'key_provider' => env('VAULT_KEY_PROVIDER', 'env'),

    // base64 encoded 32-byte key. Generate: php artisan vault:key
    'master_key' => env('VAULT_MASTER_KEY'),

    'withdrawal' => [
        // Require a valid TOTP code on every withdrawal request / admin action.
        'require_2fa' => (bool) env('WITHDRAWAL_REQUIRE_2FA', true),
        // Master switch for the auto-approval pipeline.
        'auto_enabled' => (bool) env('WITHDRAWAL_AUTO_ENABLED', true),
        // Max processing attempts before a withdrawal is marked failed.
        'max_attempts' => (int) env('WITHDRAWAL_MAX_ATTEMPTS', 3),
        // Withdrawals stuck in "processing" longer than this are flagged.
        'stuck_after_minutes' => (int) env('WITHDRAWAL_STUCK_AFTER', 120),
    ],

    'admin' => [
        // Admin panel is unusable until the admin has confirmed 2FA.
        'force_2fa' => true,
        // Re-verify TOTP for high-risk actions (approve/reject/adjust balance).
        'confirm_actions_with_2fa' => true,
    ],

    'audit' => [
        'log_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),
    ],

    // Broadcasting channel names (see routes/channels.php)
    'channels' => [
        'user' => 'user.{userId}',
        'admin' => 'admin.feed',
    ],
];
