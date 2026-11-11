@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
    @php use App\Support\Decimal; @endphp

    <h1 class="text-lg font-semibold mb-6">Overview</h1>

    {{-- ------------------------------------------------------------ KPI cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Users</p>
            <p class="text-2xl font-semibold mt-1">{{ number_format($stats['users']) }}</p>
            @if ($stats['frozen_users'] > 0)
                <p class="text-xs text-rose-600 mt-1">{{ $stats['frozen_users'] }} frozen</p>
            @endif
        </div>

        <a href="{{ route('admin.withdrawals.pending') }}" class="bg-white rounded-xl shadow-sm p-4 hover:ring-2 hover:ring-amber-300">
            <p class="text-xs uppercase tracking-wide text-slate-500">Pending approvals</p>
            <p class="text-2xl font-semibold mt-1 {{ $stats['pending_withdrawals'] > 0 ? 'text-amber-600' : '' }}">
                {{ number_format($stats['pending_withdrawals']) }}
            </p>
            <p class="text-xs text-slate-500 mt-1">{{ $stats['processing_withdrawals'] }} processing</p>
        </a>

        <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Deposits (7d)</p>
            <p class="text-2xl font-semibold mt-1">{{ number_format($stats['deposits_7d']) }}</p>
            <p class="text-xs text-slate-500 mt-1">all-time: {{ number_format((float) $stats['deposits_credited_total'], 4) }}</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Withdrawals (7d)</p>
            <p class="text-2xl font-semibold mt-1">{{ number_format($stats['withdrawals_7d']) }}</p>
            <p class="text-xs text-slate-500 mt-1">all-time: {{ number_format((float) $stats['withdrawals_completed_total'], 4) }}</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">

        {{-- ------------------------------------------------- pending approvals --}}
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm">
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                <h2 class="font-medium">Withdrawals awaiting approval</h2>
                <a href="{{ route('admin.withdrawals.pending') }}" class="text-xs text-indigo-600 hover:underline">View all</a>
            </div>

            @if ($pending->isEmpty())
                <p class="px-4 py-6 text-sm text-slate-500">Nothing pending. 🎉</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                            <th class="px-4 py-2">#</th>
                            <th class="px-4 py-2">User</th>
                            <th class="px-4 py-2">Asset</th>
                            <th class="px-4 py-2 text-right">Total</th>
                            <th class="px-4 py-2">Requested</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $withdrawal)
                            <tr class="border-b border-slate-50 last:border-0">
                                <td class="px-4 py-2 font-mono text-xs">{{ $withdrawal->id }}</td>
                                <td class="px-4 py-2">
                                    {{ $withdrawal->user?->name }}
                                    <span class="block text-xs text-slate-400">{{ $withdrawal->user?->email }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    {{ $withdrawal->asset }}
                                    <span class="block text-xs text-slate-400">{{ $withdrawal->chain?->label() }} · {{ $withdrawal->rail }}</span>
                                </td>
                                <td class="px-4 py-2 text-right font-mono">{{ Decimal::prettify($withdrawal->totalLocked()) }}</td>
                                <td class="px-4 py-2 text-xs text-slate-500">{{ $withdrawal->created_at->diffForHumans() }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('admin.withdrawals.show', $withdrawal) }}"
                                       class="rounded bg-slate-900 text-white text-xs px-3 py-1.5 hover:bg-slate-700">Review</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ---------------------------------------------------- asset exposure --}}
        <div class="bg-white rounded-xl shadow-sm">
            <div class="px-4 py-3 border-b border-slate-100">
                <h2 class="font-medium">Client balances by asset</h2>
            </div>

            @if ($assets->isEmpty())
                <p class="px-4 py-6 text-sm text-slate-500">No balances yet.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                            <th class="px-4 py-2">Asset</th>
                            <th class="px-4 py-2 text-right">Available</th>
                            <th class="px-4 py-2 text-right">Locked</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($assets as $row)
                            <tr class="border-b border-slate-50 last:border-0">
                                <td class="px-4 py-2 font-medium">{{ $row['asset'] }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $row['available'] }}</td>
                                <td class="px-4 py-2 text-right font-mono text-slate-500">{{ $row['locked'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- --------------------------------------------------- recent deposits --}}
        <div class="lg:col-span-3 bg-white rounded-xl shadow-sm">
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                <h2 class="font-medium">Recent deposits</h2>
                <a href="{{ route('admin.deposits.index') }}" class="text-xs text-indigo-600 hover:underline">View all</a>
            </div>

            @if ($recentDeposits->isEmpty())
                <p class="px-4 py-6 text-sm text-slate-500">No deposits detected yet.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                            <th class="px-4 py-2">#</th>
                            <th class="px-4 py-2">User</th>
                            <th class="px-4 py-2">Chain / asset</th>
                            <th class="px-4 py-2 text-right">Amount</th>
                            <th class="px-4 py-2">Confirmations</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Detected</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentDeposits as $deposit)
                            <tr class="border-b border-slate-50 last:border-0">
                                <td class="px-4 py-2 font-mono text-xs">{{ $deposit->id }}</td>
                                <td class="px-4 py-2">{{ $deposit->user?->name }}</td>
                                <td class="px-4 py-2">
                                    {{ $deposit->asset }}
                                    <span class="block text-xs text-slate-400">{{ $deposit->chain?->label() }}</span>
                                </td>
                                <td class="px-4 py-2 text-right font-mono">{{ Decimal::prettify($deposit->amount) }}</td>
                                <td class="px-4 py-2 text-xs">
                                    {{ $deposit->confirmations }}/{{ $deposit->required_confirmations }}
                                </td>
                                <td class="px-4 py-2">
                                    <span class="rounded px-2 py-0.5 text-xs {{ $deposit->status->value === 'credited' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $deposit->status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-xs text-slate-500">{{ $deposit->detected_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
