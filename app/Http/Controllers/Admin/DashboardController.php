<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DepositStatus;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Admin overview: totals, pending queue, recent activity. */
final class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'users' => User::query()->where('role', 'user')->count(),
            'frozen_users' => User::query()->where('status', '!=', 'active')->count(),
            'pending_withdrawals' => Withdrawal::query()->pending()->count(),
            'processing_withdrawals' => Withdrawal::query()->processing()->count(),
            'deposits_credited_total' => (string) Deposit::query()->where('status', DepositStatus::Credited)->sum('amount'),
            'withdrawals_completed_total' => (string) Withdrawal::query()->where('status', WithdrawalStatus::Completed)->sum('amount'),
            'deposits_7d' => Deposit::query()->where('status', DepositStatus::Credited)->where('credited_at', '>=', now()->subDays(7))->count(),
            'withdrawals_7d' => Withdrawal::query()->where('status', WithdrawalStatus::Completed)->where('completed_at', '>=', now()->subDays(7))->count(),
        ];

        $pending = Withdrawal::query()->pending()->with('user:id,name,email')->oldest()->limit(10)->get();

        $recentDeposits = Deposit::query()->with('user:id,name,email')->latest()->limit(10)->get();

        $assets = DB::table('balances')
            ->selectRaw('asset, SUM(available) as available, SUM(locked) as locked')
            ->groupBy('asset')
            ->orderByDesc('available')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'asset' => $row->asset,
                'available' => Decimal::prettify((string) $row->available),
                'locked' => Decimal::prettify((string) $row->locked),
            ]);

        return view('admin.dashboard', compact('stats', 'pending', 'recentDeposits', 'assets'));
    }
}
