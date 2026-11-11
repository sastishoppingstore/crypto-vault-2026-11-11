<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WithdrawalStatus;
use App\Exceptions\VaultException;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Security\TwoFactorService;
use App\Services\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin withdrawal management (web):
 *   GET  /admin/withdrawals          (filter: ?status=)
 *   GET  /admin/withdrawals/pending
 *   GET  /admin/withdrawals/{id}
 *   POST /admin/withdrawals/{id}/approve   (requires 2FA code)
 *   POST /admin/withdrawals/{id}/reject    (requires 2FA code + reason)
 */
final class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function index(Request $request): View
    {
        $status = $request->route('status') ?? $request->query('status');

        $query = Withdrawal::query()->with('user:id,name,email')->latest();

        if ($status && $value = WithdrawalStatus::tryFrom((string) $status)) {
            $query->where('status', $value);
        }

        return view('admin.withdrawals.index', [
            'withdrawals' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'statuses' => WithdrawalStatus::cases(),
            'pendingCount' => Withdrawal::query()->pending()->count(),
        ]);
    }

    public function show(Withdrawal $withdrawal): View
    {
        $withdrawal->load('user', 'approver:id,name', 'rejector:id,name');

        return view('admin.withdrawals.show', compact('withdrawal'));
    }

    public function approve(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        if ($response = $this->guardCode($request)) {
            return $response;
        }

        try {
            $this->withdrawals->approve($request->user(), $withdrawal);

            return back()->with('status', "Withdrawal #{$withdrawal->id} approved and queued.");
        } catch (VaultException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }
    }

    public function reject(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($response = $this->guardCode($request)) {
            return $response;
        }

        try {
            $this->withdrawals->reject($request->user(), $withdrawal, $data['reason']);

            return back()->with('status', "Withdrawal #{$withdrawal->id} rejected — funds unlocked.");
        } catch (VaultException $e) {
            return back()->withErrors(['reject' => $e->getMessage()]);
        }
    }

    /** High-risk actions always require a fresh TOTP code from the admin. */
    private function guardCode(Request $request): ?RedirectResponse
    {
        if (! config('vault.admin.confirm_actions_with_2fa', true)) {
            return null;
        }

        $code = (string) $request->input('two_factor_code', '');
        if ($code === '' || ! $this->twoFactor->verifyUserCode($request->user(), $code)) {
            return back()->withErrors(['two_factor_code' => 'Invalid or missing two-factor code.']);
        }

        return null;
    }
}
