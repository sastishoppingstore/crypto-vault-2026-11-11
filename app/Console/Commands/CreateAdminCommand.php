<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Security\TwoFactorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'vault:create-admin
        {--name= : Admin display name}
        {--email= : Admin email}
        {--password= : Password (prompted securely if omitted)}
        {--confirm-2fa : Skip the QR scan and activate 2FA immediately (prints recovery codes)}';

    protected $description = 'Create an admin user for the panel (2FA is mandatory)';

    public function handle(TwoFactorService $twoFactor): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Admin name'));
        $email = strtolower((string) ($this->option('email') ?: $this->ask('Admin email')));
        $password = (string) ($this->option('password') ?: $this->secret('Password (min 12 chars)'));

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $secret = $twoFactor->generateSecret();

        $admin = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $this->option('confirm-2fa') ? now() : null,
        ]);

        $this->components->info("Admin [{$email}] created (#{$admin->id}).");

        if ($this->option('confirm-2fa')) {
            $codes = $twoFactor->generateRecoveryCodes();
            $admin->forceFill(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes)])->save();

            $this->components->warn('2FA activated. Recovery codes (store offline, shown once):');
            foreach ($codes as $code) {
                $this->line('  '.$code);
            }
            $this->newLine();
            $this->components->info('Add the TOTP secret to your authenticator app manually:');
            $this->line('  '.$secret);
        } else {
            $this->components->info('Log in to /admin and scan the QR code to finish 2FA setup.');
        }

        return self::SUCCESS;
    }
}
