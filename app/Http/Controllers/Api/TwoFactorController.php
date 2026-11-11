<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Security\AuditLogger;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** TOTP setup / confirm / disable for API users. */
final class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Start setup: returns secret + otpauth URL + inline QR SVG. */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => '2FA is already enabled.'], 409);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => $this->twoFactor->qrCodeSvg($url),
        ]);
    }

    /** Confirm setup with the first code; recovery codes are shown once. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        if ($codes === null) {
            return response()->json(['message' => 'Invalid code — 2FA was not enabled.'], 422);
        }

        $this->audit->log(null, '2fa.enabled', $request->user(), null, null, 'User enabled 2FA');

        return response()->json([
            'message' => '2FA enabled. Store the recovery codes somewhere safe — they are shown only once.',
            'recovery_codes' => $codes,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $this->twoFactor->disable($request->user(), $data['code'])) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        $this->audit->log(null, '2fa.disabled', $request->user(), null, null, 'User disabled 2FA');

        return response()->json(['message' => '2FA disabled.']);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['enabled' => $request->user()->hasTwoFactorEnabled()]);
    }
}
