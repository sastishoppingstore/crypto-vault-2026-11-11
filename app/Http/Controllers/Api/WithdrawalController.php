<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalRequestRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** User withdrawal endpoints. */
final class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals)
    {
    }

    /**
     * POST /api/withdraw/request
     * Requires X-2FA-Code header (middleware '2fa:withdrawal').
     * Supports Idempotency-Key header for safe retries.
     */
    public function store(WithdrawalRequestRequest $request): JsonResponse
    {
        $withdrawal = $this->withdrawals->request($request->user(), $request->validated());

        return response()->json([
            'withdrawal' => new WithdrawalResource($withdrawal),
            'message' => $withdrawal->approval_type === 'auto'
                ? 'Withdrawal queued for automatic processing.'
                : 'Withdrawal submitted for manual approval.',
        ], 201);
    }

    /** GET /api/withdrawals — the user's withdrawal history. */
    public function index(Request $request): JsonResponse
    {
        $query = Withdrawal::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            WithdrawalResource::collection($query->paginate(25)->withQueryString())
        );
    }
}
