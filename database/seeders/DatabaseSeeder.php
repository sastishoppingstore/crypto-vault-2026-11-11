<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Admin accounts are NOT created here — use:
     *   php artisan vault:create-admin you@example.com
     */
    public function run(): void
    {
        $this->call(SettingsSeeder::class);

        // Local demo user only (never seeded in production/staging).
        if (app()->environment('local')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
