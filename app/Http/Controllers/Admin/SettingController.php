<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\AuditLogger;
use App\Services\Security\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

/**
 * Editable runtime settings. Secrets (API keys) are NOT editable here on
 * purpose — they live encrypted in api_keys and are managed via console/DB
 * with their own audit trail.
 */
final class SettingController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): View
    {
        return view('admin.settings.index', [
            'autoEnabled' => $this->settings->bool('withdrawal.auto_enabled', true),
            'assets' => $this->settings->array('withdrawal.assets', []),
            'confirmations' => $this->settings->array('deposits.confirmations', []),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_enabled' => ['nullable', 'boolean'],
            'assets_json' => ['required', 'string'],
            'confirmations_json' => ['required', 'string'],
        ]);

        $assets = json_decode($data['assets_json'], true);
        $confirmations = json_decode($data['confirmations_json'], true);

        if (! is_array($assets)) {
            throw ValidationException::withMessages(['assets_json' => 'Assets must be a valid JSON object.']);
        }
        if (! is_array($confirmations)) {
            throw ValidationException::withMessages(['confirmations_json' => 'Confirmations must be a valid JSON object.']);
        }

        foreach ($assets as $asset => $config) {
            $ok = is_array($config)
                && isset($config['enabled'], $config['rail'], $config['min'], $config['fee'], $config['auto_limit'])
                && in_array($config['rail'], ['binance', 'onchain'], true)
                && preg_match('/^\d+(\.\d{1,18})?$/', (string) $config['min'])
                && preg_match('/^\d+(\.\d{1,18})?$/', (string) $config['fee'])
                && preg_match('/^\d+(\.\d{1,18})?$/', (string) $config['auto_limit']);

            if (! $ok) {
                throw ValidationException::withMessages([
                    'assets_json' => "Invalid config for asset [{$asset}] — needs enabled, rail (binance|onchain), min, fee, auto_limit.",
                ]);
            }
        }

        $old = [
            'auto_enabled' => $this->settings->bool('withdrawal.auto_enabled', true),
            'assets' => $this->settings->array('withdrawal.assets', []),
            'confirmations' => $this->settings->array('deposits.confirmations', []),
        ];

        $this->settings->set('withdrawal.auto_enabled', (bool) $request->boolean('auto_enabled'), 'withdrawal');
        $this->settings->set('withdrawal.assets', $assets, 'withdrawal');
        $this->settings->set('deposits.confirmations', array_map('intval', $confirmations), 'deposits');

        $this->audit->log($request->user(), 'settings.updated', null, $old, [
            'auto_enabled' => (bool) $request->boolean('auto_enabled'),
            'assets' => $assets,
            'confirmations' => array_map('intval', $confirmations),
        ], 'Withdrawal/deposit settings updated');

        return back()->with('status', 'Settings saved.');
    }
}
