<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies provider webhook signatures (HMAC-SHA256 over the raw body) and an
 * optional IP allowlist before the payload is trusted.
 *
 * Provider config: config/webhooks.php ("providers" array).
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, string $provider = ''): Response
    {
        // Provider may come from the route parameter (POST /api/webhooks/{provider}).
        $provider = $provider !== '' ? $provider : (string) $request->route('provider');

        $config = config("webhooks.providers.{$provider}");

        if (! is_array($config)) {
            abort(404, "Unknown webhook provider [{$provider}].");
        }

        // Optional IP allowlist (e.g. provider egress IPs).
        $allowedIps = (array) ($config['allowed_ips'] ?? []);
        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            abort(401, 'Webhook source IP not allowed.');
        }

        $secret = (string) ($config['secret'] ?? '');
        if ($secret === '') {
            abort(503, "Webhook secret for [{$provider}] is not configured.");
        }

        $header = (string) ($config['signature_header'] ?? 'X-Signature');
        $signature = (string) $request->header($header, '');

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // Providers may prefix the signature (e.g. "sha256=...").
        $normalized = str_contains($signature, '=') ? explode('=', $signature, 2)[1] : $signature;

        if ($signature === '' || ! hash_equals($expected, strtolower($normalized))) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
