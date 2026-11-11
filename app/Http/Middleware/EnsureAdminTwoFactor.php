<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate (session based):
 *  1. admin must exist and be active;
 *  2. 2FA must be set up (otherwise forced to the setup screen);
 *  3. session flag must be fresh (re-verify every 30 minutes).
 */
class EnsureAdminTwoFactor
{
    private const SESSION_KEY = 'admin.2fa.passed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin || ! $admin->isAdmin() || ! $admin->isActive()) {
            return redirect()->route('admin.login');
        }

        if (! $admin->hasTwoFactorEnabled()) {
            return $request->routeIs('admin.2fa.setup', 'admin.2fa.confirm')
                ? $next($request)
                : redirect()->route('admin.2fa.setup');
        }

        $passedAt = $request->session()->get(self::SESSION_KEY);

        if (! $passedAt || now()->diffInMinutes($passedAt) > 30) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('admin.2fa.challenge');
        }

        return $next($request);
    }

    public static function markPassed(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now());
    }
}
