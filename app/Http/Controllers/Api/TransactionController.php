<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/transactions — user ledger with filters + pagination. */
final class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'asset' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()
            ->transactions()
            ->latest();

        if ($asset = $request->query('asset')) {
            $query->where('asset', strtoupper($asset));
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        return response()->json(
            TransactionResource::collection($query->paginate((int) $request->query('per_page', 25))->withQueryString())
        );
    }
}
