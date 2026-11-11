<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\VaultSecret;
use App\Services\Security\EncryptionService;
use App\Services\Security\EnvKeyProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every vault ciphertext with the active VAULT_MASTER_KEY.
 *
 * Rotation procedure:
 *   1. Old key A is in use. Generate key B:  php artisan vault:key
 *   2. Put B into .env as VAULT_MASTER_KEY.
 *   3. Run:  php artisan vault:reencrypt --old-key=<A, with or without base64:>
 *   4. Verify logins / address derivation, then destroy key A.
 */
class ReencryptCommand extends Command
{
    protected $signature = 'vault:reencrypt {--old-key= : Previous master key (base64:... or hex)} {--force : Skip the confirmation prompt}';

    protected $description = 'Re-encrypt vault secrets with the current VAULT_MASTER_KEY (key rotation)';

    public function handle(): int
    {
        $oldKey = (string) $this->option('old-key');

        if ($oldKey === '') {
            $this->components->error('Provide the previous key via --old-key.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Rotate all vault ciphertexts to the active key?', true)) {
            return self::SUCCESS;
        }

        config(['vault.key_provider' => 'env']);
        $old = new EncryptionService(new class($oldKey) implements \App\Services\Security\KeyProviderInterface {
            public function __construct(private readonly string $explicit)
            {
            }

            public function key(): string
            {
                $configured = $this->explicit;
                $key = match (true) {
                    str_starts_with($configured, 'base64:') => base64_decode(substr($configured, 7), true),
                    (bool) preg_match('/^[0-9a-fA-F]{64}$/', $configured) => hex2bin($configured),
                    default => $configured,
                };

                if ($key === false || strlen($key) !== 32) {
                    throw new \RuntimeException('--old-key must decode to exactly 32 bytes.');
                }

                return $key;
            }

            public function name(): string
            {
                return 'env';
            }
        });
        $new = app(EncryptionService::class);

        if ($old->keyId() === $new->keyId()) {
            $this->components->error('Old key and active key are identical — nothing to rotate.');

            return self::FAILURE;
        }

        $count = 0;

        // vault_secrets.value
        foreach (VaultSecret::query()->get() as $secret) {
            $raw = (string) $secret->getRawOriginal('value');
            if (! str_starts_with($raw, 'v1:')) {
                continue;
            }
            try {
                DB::table('vault_secrets')->where('id', $secret->id)
                    ->update(['value' => $new->encrypt($old->decrypt($raw))]);
                $count++;
            } catch (\Throwable $e) {
                $this->components->warn("vault_secrets#{$secret->id} skipped: {$e->getMessage()}");
            }
        }

        // api_keys.key / api_keys.secret
        foreach (ApiKey::query()->get() as $apiKey) {
            $updates = [];
            foreach (['key', 'secret'] as $column) {
                $raw = (string) $apiKey->getRawOriginal($column);
                if (! str_starts_with($raw, 'v1:')) {
                    continue;
                }
                try {
                    $updates[$column] = $new->encrypt($old->decrypt($raw));
                } catch (\Throwable $e) {
                    $this->components->warn("api_keys#{$apiKey->id}.{$column} skipped: {$e->getMessage()}");
                }
            }
            if ($updates !== []) {
                DB::table('api_keys')->where('id', $apiKey->id)->update($updates);
                $count += count($updates);
            }
        }

        // users.two_factor_secret / two_factor_recovery_codes
        foreach (User::query()->whereNotNull('two_factor_secret')->get() as $user) {
            $updates = [];
            foreach (['two_factor_secret', 'two_factor_recovery_codes'] as $column) {
                $raw = $user->getRawOriginal($column);
                if (! is_string($raw) || ! str_starts_with($raw, 'v1:')) {
                    continue;
                }
                try {
                    $updates[$column] = $new->encrypt($old->decrypt($raw));
                } catch (\Throwable $e) {
                    $this->components->warn("users#{$user->id}.{$column} skipped: {$e->getMessage()}");
                }
            }
            if ($updates !== []) {
                DB::table('users')->where('id', $user->id)->update($updates);
                $count += count($updates);
            }
        }

        $this->components->info("Re-encrypted {$count} value(s) with key [{$new->keyId()}].");

        return self::SUCCESS;
    }
}
