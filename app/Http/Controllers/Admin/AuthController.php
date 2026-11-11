<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

/**
 * Admin panel authentication: password + mandatory TOTP.
 * Session flow: login -> (challenge | setup) -> dashboard, re-verify 30min.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    public function showLogin(): View
    {
        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }
        if (! $user->isAdmin()) {
            throw ValidationException::withMessages(['email' => 'This account has no admin access.']);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'Account is disabled.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->audit->log($user, 'admin.login.password_ok', $user);

        return $user->hasTwoFactorEnabled()
            ? redirect()->route('admin.2fa.challenge')
            : redirect()->route('admin.2fa.setup');
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();

        if (! $this->twoFactor->verifyUserCode($user, $data['code'])) {
            $this->audit->log($user, 'admin.login.2fa_failed', $user, null, null, 'Invalid TOTP on challenge');

            return back()->withErrors(['code' => 'Invalid two-factor code.']);
        }

        EnsureAdminTwoFactor::markPassed($request);
        $this->audit->log($user, 'admin.login.success', $user);

        return redirect()->route('admin.dashboard');
    }

    public function showSetup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('admin.login');
        }
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $user->two_factor_secret) {
            $user->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret()])->save();
        }

        $url = $this->twoFactor->otpauthUrl($user, (string) $user->two_factor_secret);

        return view('admin.auth.two-factor-setup', [
            'secret' => $user->two_factor_secret,
            'otpauthUrl' => $url,
            'qrSvg' => $this->twoFactor->qrCodeSvg($url),
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();

        $codes = $this->twoFactor->confirm($user, $data['code']);
        if ($codes === null) {
            return back()->withErrors(['code' => 'Invalid code — try again.']);
        }

        EnsureAdminTwoFactor::markPassed($request);
        $this->audit->log($user, 'admin.2fa.enabled', $user);

        return redirect()->route('admin.dashboard')
            ->with('recovery_codes', $codes)
            ->with('status', 'Two-factor authentication is now enabled.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->log($request->user(), 'admin.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
