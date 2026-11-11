@extends('admin.layout')

@section('title', 'Audit log')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="text-lg font-semibold">Audit log</h1>

        <form method="GET" action="{{ route('admin.logs.index') }}" class="flex flex-wrap gap-2">
            <input type="text" name="action" value="{{ $action }}" placeholder="Filter action (e.g. withdrawal.)"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-slate-400">
            <input type="text" name="admin_id" value="{{ request()->query('admin_id') }}" placeholder="Admin ID"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm w-28 focus:outline-none focus:ring-2 focus:ring-slate-400">
            <button class="rounded-lg bg-slate-900 text-white text-sm px-4 py-1.5 hover:bg-slate-700">Filter</button>
            @if ($action !== '' || request()->query('admin_id'))
                <a href="{{ route('admin.logs.index') }}"
                   class="rounded-lg border border-slate-300 text-sm px-3 py-1.5 text-slate-600 hover:border-slate-400">Clear</a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        @if ($logs->isEmpty())
            <p class="px-4 py-8 text-sm text-slate-500">No log entries match the filter.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100 bg-slate-50">
                        <th class="px-4 py-2.5">When</th>
                        <th class="px-4 py-2.5">Admin</th>
                        <th class="px-4 py-2.5">Action</th>
                        <th class="px-4 py-2.5">Subject</th>
                        <th class="px-4 py-2.5">Description</th>
                        <th class="px-4 py-2.5">IP</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr class="border-b border-slate-50 last:border-0 align-top">
                            <td class="px-4 py-2.5 text-xs text-slate-500 whitespace-nowrap">
                                {{ $log->created_at->format('Y-m-d H:i:s') }}
                            </td>
                            <td class="px-4 py-2.5">
                                {{ $log->admin?->name ?? 'system' }}
                                <span class="block text-xs text-slate-400">{{ $log->admin?->email }}</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="font-mono text-xs rounded bg-slate-100 px-1.5 py-0.5">{{ $log->action }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                @if ($log->subject_type)
                                    {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                @else —
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600 max-w-xs">{{ $log->description }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-400 font-mono">{{ $log->ip_address }}</td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($log->old_values || $log->new_values)
                                    <details>
                                        <summary class="text-xs text-indigo-600 cursor-pointer hover:underline">diff</summary>
                                        <pre class="mt-2 text-left text-[10px] bg-slate-50 rounded p-2 max-w-md overflow-auto">{{ json_encode(['old' => $log->old_values, 'new' => $log->new_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
@endsection
