<?php

namespace App\Services\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238) with one-time recovery codes.
 * Used for user logins, withdrawal requests and every high-risk admin action.
 */
final class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name', 'CryptoVault'),
            $user->email,
            $secret,
        );
    }

    /** Inline SVG QR code for the admin panel (no external service). */
    public function qrCodeSvg(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $writer = new Writer($renderer);

        return $writer->writeString($otpauthUrl);
    }

    /** Verify a TOTP code with a +/- 1 step window. */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return $code !== '' && (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    /** Accepts a live TOTP code OR a valid (single-use) recovery code. */
    public function verifyUserCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        if ($this->verify((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Confirm 2FA setup: verify the first code and issue recovery codes.
     * @return array<int, string>|null plaintext recovery codes (shown once)
     */
    public function confirm(User $user, string $code): ?array
    {
        $secret = (string) $user->two_factor_secret;
        if ($secret === '' || ! $this->verify($secret, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->hashRecoveryCodes($codes),
        ])->save();

        return $codes;
    }

    /** Disable 2FA (requires a valid current code). */
    public function disable(User $user, string $code): bool
    {
        if (! $this->verifyUserCode($user, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return true;
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(int $count = 10): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(bin2hex(random_bytes(5)))) // 10 hex chars
            ->all();
    }

    /**
     * Recovery codes are stored as keyed hashes — plaintext is never kept.
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => $this->hashRecoveryCode($code), $codes);
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }

    /** Consume a recovery code if it matches; the code can be used only once. */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $needle = $this->hashRecoveryCode($code);

        $index = array_search($needle, $hashes, true);
        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

        return true;
    }
}
