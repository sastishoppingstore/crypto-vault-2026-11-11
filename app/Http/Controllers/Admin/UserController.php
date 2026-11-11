<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\Security\AuditLogger;
use App\Services\Security\TwoFactorService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin user management: balances adjustments, freeze/unfreeze. */
final class UserController extends Controller
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $query = User::query()->latest();

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return view('admin.users.index', [
            'users' => $query->paginate(25)->withQueryString(),
            'search' => $search ?? '',
        ]);
    }

    public function show(User $user): View
    {
        $user->load([
            'balances',
            'wallets',
            'transactions' => fn ($q) => $q->latest()->limit(25),
            'withdrawals' => fn ($q) => $q->latest()->limit(10),
        ]);

        return view('admin.users.show', compact('user'));
    }

    /** Manual balance correction (audit logged, signed amount). */
    public function adjustBalance(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'asset' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'string', 'regex:/^-?\d+(\.\d{1,18})?$/'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($response = $this->guardCode($request)) {
            return $response;
        }

        if (Decimal::isZero($data['amount'])) {
            return back()->withErrors(['amount' => 'Amount cannot be zero.']);
        }

        $asset = strtoupper($data['asset']);

        try {
            $transaction = $this->balances->adjust($user, $asset, $data['amount'], $data['reason'], $request->user());
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        $this->audit->log($request->user(), 'user.balance_adjusted', $user, null, [
            'asset' => $asset,
            'delta' => Decimal::prettify($data['amount']),
            'reason' => $data['reason'],
            'transaction_id' => $transaction->id,
        ], "Balance adjusted by {$data['amount']} {$asset}");

        return back()->with('status', "Balance adjusted: {$data['amount']} {$asset}.");
    }

    public function toggleFreeze(Request $request, User $user): RedirectResponse
    {
        if ($response = $this->guardCode($request)) {
            return $response;
        }

        $old = $user->status->value;
        $frozen = $user->status !== UserStatus::Active;

        $user->forceFill([
            'status' => $frozen ? UserStatus::Active : UserStatus::Frozen,
            'frozen_at' => $frozen ? null : now(),
        ])->save();

        $this->audit->log($request->user(), $frozen ? 'user.unfrozen' : 'user.frozen', $user, [
            'status' => $old,
        ], ['status' => $user->status->value]);

        return back()->with('status', $frozen ? 'User unfrozen.' : 'User frozen — withdrawals disabled.');
    }

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
