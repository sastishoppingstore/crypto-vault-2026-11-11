<?php

namespace App\Http\Middleware;

use App\Services\Security\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a fresh TOTP code on every sensitive API action.
 * Usage: ->middleware('2fa:withdrawal')
 *
 * The code is read from the X-2FA-Code header (or two_factor_code field) and
 * a used code is rejected for the next 90 seconds (replay protection).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next, string $scope = 'default'): Response
    {
        if (! config('vault.withdrawal.require_2fa', true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication must be enabled before this action.',
                'code' => '2fa_setup_required',
            ], 403);
        }

        $code = trim((string) ($request->header('X-2FA-Code') ?? $request->input('two_factor_code', '')));

        if ($code === '') {
            return response()->json([
                'message' => 'X-2FA-Code header is required.',
                'code' => '2fa_code_required',
            ], 403);
        }

        $replayKey = 'vault.2fa.used.'.$user->id.'.'.hash('sha256', $code.'|'.$scope);

        if (Cache::has($replayKey)) {
            return response()->json([
                'message' => 'This 2FA code was already used. Wait for the next code.',
                'code' => '2fa_code_replayed',
            ], 429);
        }

        if (! app(TwoFactorService::class)->verifyUserCode($user, $code)) {
            return response()->json([
                'message' => 'Invalid two-factor code.',
                'code' => '2fa_invalid',
            ], 403);
        }

        Cache::put($replayKey, true, now()->addSeconds(90));

        return $next($request);
    }
}
