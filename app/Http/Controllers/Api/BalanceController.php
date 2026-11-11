<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BalanceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/balance — current available/locked balances. */
final class BalanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $balances = $request->user()
            ->balances()
            ->orderBy('asset')
            ->get();

        return response()->json([
            'balances' => BalanceResource::collection($balances),
        ]);
    }
}
