<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Audit trail browser. */
final class LogController extends Controller
{
    public function index(Request $request): View
    {
        $query = AdminLog::query()->with('admin:id,name,email')->latest('id');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($adminId = $request->query('admin_id')) {
            $query->where('admin_id', $adminId);
        }

        return view('admin.logs.index', [
            'logs' => $query->paginate(50)->withQueryString(),
            'action' => $action ?? '',
        ]);
    }
}
