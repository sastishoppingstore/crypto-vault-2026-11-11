<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\CheckWithdrawalStatusJob;
use App\Jobs\ScanChainDepositsJob;
use App\Jobs\SyncExchangeDepositsJob;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => App\Http\Middleware\EnsureUserIsAdmin::class,
            'admin.2fa' => App\Http\Middleware\EnsureAdminTwoFactor::class,
            '2fa' => App\Http\Middleware\RequireTwoFactor::class,
            'webhook.signature' => App\Http\Middleware\VerifyWebhookSignature::class,
        ]);

        // Behind nginx/load balancer: set TRUSTED_PROXIES=127.0.0.1 in .env.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: explode(',', (string) $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Domain errors surface as clean 422/502 responses.
        $exceptions->render(function (App\Exceptions\VaultException $e, Illuminate\Http\Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['error' => $e->getMessage()]);
        });
        $exceptions->render(function (App\Exceptions\InsufficientBalanceException $e, Illuminate\Http\Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['error' => $e->getMessage()]);
        });
        $exceptions->render(function (App\Exceptions\BinanceException $e, Illuminate\Http\Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 502)
                : back()->withErrors(['error' => $e->getMessage()]);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // One deposit scan per enabled chain, every minute (queued, no overlap).
        foreach (App\Enums\Chain::enabledChains() as $chain) {
            $schedule->job(new ScanChainDepositsJob($chain))
                ->everyMinute()
                ->withoutOverlapping("scan-{$chain->value}")
                ->onOneServer();
        }

        // Exchange treasury sync + withdrawal confirmation polling.
        $schedule->job(new SyncExchangeDepositsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new CheckWithdrawalStatusJob)
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        $schedule->command('vault:prune-logs')->daily();
    })
    ->create();
