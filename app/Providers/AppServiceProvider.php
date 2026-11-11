<?php

namespace App\Providers;

use App\Services\Security\AwsKmsKeyProvider;
use App\Services\Security\EncryptionService;
use App\Services\Security\EnvKeyProvider;
use App\Services\Security\KeyProviderInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Vault key provider: env (default) or an HSM/KMS implementation.
        $this->app->singleton(KeyProviderInterface::class, function (): KeyProviderInterface {
            return match ((string) config('vault.key_provider', 'env')) {
                'hsm' => new AwsKmsKeyProvider(),
                default => new EnvKeyProvider(),
            };
        });

        $this->app->singleton(
            EncryptionService::class,
            fn ($app) => new EncryptionService($app->make(KeyProviderInterface::class)),
        );
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('withdrawals', function (Request $request) {
            return Limit::perHour(10)
                ->by((string) $request->user()?->id)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many withdrawal requests. Please try again later.',
                ], 429, $headers));
        });
    }
}
