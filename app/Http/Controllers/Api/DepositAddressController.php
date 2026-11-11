<?php

namespace App\Http\Controllers\Api;

use App\Enums\Chain;
use App\Http\Controllers\Controller;
use App\Http\Requests\DepositAddressRequest;
use App\Http\Resources\WalletResource;
use App\Services\Blockchain\WalletService;
use Illuminate\Http\JsonResponse;

/** POST /api/deposit/address — get (or provision) a per-user deposit address. */
final class DepositAddressController extends Controller
{
    public function __construct(private readonly WalletService $wallets)
    {
    }

    public function store(DepositAddressRequest $request): JsonResponse
    {
        $chain = Chain::from($request->validated('chain'));
        $wallet = $this->wallets->depositWallet($request->user(), $chain);

        return response()->json([
            'wallet' => new WalletResource($wallet->loadMissing('user')),
            'notice' => 'Only send supported assets on this chain. Funds sent to a wrong network may be lost.',
        ], 201);
    }
}
