<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Chain;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** On-chain deposit monitor. */
final class DepositController extends Controller
{
    public function index(Request $request): View
    {
        $query = Deposit::query()->with('user:id,name,email')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($chain = $request->query('chain')) {
            $query->where('chain', $chain);
        }

        return view('admin.deposits.index', [
            'deposits' => $query->paginate(25)->withQueryString(),
            'status' => $status ?? '',
            'chain' => $chain ?? '',
            'chains' => Chain::cases(),
        ]);
    }
}
