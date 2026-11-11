<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WithdrawalResource;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Security\TwoFactorService;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin withdrawal API (token auth, ability: admin).
 *
 *   GET  /api/admin/withdrawals/pending
 *   POST /api/admin/withdrawals/approve/{withdrawal}
 *   POST /api/admin/withdrawals/reject/{withdrawal}
 *   GET  /api/admin/stats
 *
 * Approve/reject require a fresh TOTP code in `two_factor_code`
 * (vault.admin.confirm_actions_with_2fa).
 */
final class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function pending(): JsonResponse
    {
        $query = Withdrawal::query()->pending()->oldest();

        return response()->json([
            'pending_count' => (clone $query)->count(),
            'data' => WithdrawalResource::collection($query->paginate(50)->withQueryString()),
        ]);
    }

    public function approve(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($response = $this->verifyAdminCode($request)) {
            return $response;
        }

        $this->withdrawals->approve($request->user(), $withdrawal);

        return response()->json([
            'message' => "Withdrawal #{$withdrawal->id} approved and queued for processing.",
            'withdrawal' => new WithdrawalResource($withdrawal->fresh()),
        ]);
    }

    public function reject(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($response = $this->verifyAdminCode($request)) {
            return $response;
        }

        $this->withdrawals->reject($request->user(), $withdrawal, $data['reason']);

        return response()->json([
            'message' => "Withdrawal #{$withdrawal->id} rejected — funds unlocked.",
            'withdrawal' => new WithdrawalResource($withdrawal->fresh()),
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'users' => User::query()->where('role', 'user')->count(),
            'pending_withdrawals' => Withdrawal::query()->pending()->count(),
            'processing_withdrawals' => Withdrawal::query()->processing()->count(),
            'completed_withdrawals' => Withdrawal::query()->where('status', WithdrawalStatus::Completed)->count(),
            'credited_deposits' => \App\Models\Deposit::query()->where('status', 'credited')->count(),
        ]);
    }

    /** Verify the admin's TOTP code (required for high-risk actions). */
    private function verifyAdminCode(Request $request): ?JsonResponse
    {
        if (! config('vault.admin.confirm_actions_with_2fa', true)) {
            return null;
        }

        $admin = $request->user();
        if (! $admin->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Admin 2FA must be enabled.'], 403);
        }

        $code = (string) $request->input('two_factor_code', '');
        if ($code === '' || ! $this->twoFactor->verifyUserCode($admin, $code)) {
            return response()->json(['message' => 'Invalid two-factor code.'], 422);
        }

        return null;
    }
}
