@extends('admin.layout')

@section('title', 'Deposits')

@section('content')
    @php
        use App\Enums\DepositStatus;
        use App\Support\Decimal;

        $statusBadges = [
            'detected' => 'bg-sky-100 text-sky-800',
            'confirming' => 'bg-amber-100 text-amber-800',
            'credited' => 'bg-emerald-100 text-emerald-800',
            'orphaned' => 'bg-slate-200 text-slate-700',
            'failed' => 'bg-rose-100 text-rose-800',
        ];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="text-lg font-semibold">Deposits</h1>

        <form method="GET" action="{{ route('admin.deposits.index') }}" class="flex flex-wrap gap-2">
            <select name="status" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <option value="">All statuses</option>
                @foreach (DepositStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>

            <select name="chain" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <option value="">All chains</option>
                @foreach ($chains as $case)
                    <option value="{{ $case->value }}" @selected($chain === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>

            <button class="rounded-lg bg-slate-900 text-white text-sm px-4 py-1.5 hover:bg-slate-700">Filter</button>
            @if ($status !== '' || $chain !== '')
                <a href="{{ route('admin.deposits.index') }}"
                   class="rounded-lg border border-slate-300 text-sm px-3 py-1.5 text-slate-600 hover:border-slate-400">Clear</a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        @if ($deposits->isEmpty())
            <p class="px-4 py-8 text-sm text-slate-500">No deposits match the filter.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100 bg-slate-50">
                        <th class="px-4 py-2.5">#</th>
                        <th class="px-4 py-2.5">User</th>
                        <th class="px-4 py-2.5">Chain / asset</th>
                        <th class="px-4 py-2.5 text-right">Amount</th>
                        <th class="px-4 py-2.5">From</th>
                        <th class="px-4 py-2.5">Tx</th>
                        <th class="px-4 py-2.5">Confirmations</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">Detected</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deposits as $deposit)
                        <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono text-xs">{{ $deposit->id }}</td>
                            <td class="px-4 py-2.5">
                                {{ $deposit->user?->name }}
                                <span class="block text-xs text-slate-400">{{ $deposit->user?->email }}</span>
                            </td>
                            <td class="px-4 py-2.5">
                                {{ $deposit->asset }}
                                <span class="block text-xs text-slate-400">{{ $deposit->chain?->label() }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ Decimal::prettify($deposit->amount) }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-500 max-w-[10rem] truncate" title="{{ $deposit->from_address }}">
                                {{ $deposit->from_address ? \Illuminate\Support\Str::limit($deposit->from_address, 14) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs max-w-[10rem] truncate" title="{{ $deposit->tx_hash }}">
                                {{ \Illuminate\Support\Str::limit($deposit->tx_hash, 14) }}
                                @if ($deposit->log_index !== null)
                                    <span class="text-slate-400">/{{ $deposit->log_index }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $deposit->progress() >= 100 ? 'bg-emerald-500' : 'bg-amber-400' }}"
                                             style="width: {{ $deposit->progress() }}%"></div>
                                    </div>
                                    <span class="text-xs text-slate-500">{{ $deposit->confirmations }}/{{ $deposit->required_confirmations }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="rounded px-2 py-0.5 text-xs whitespace-nowrap {{ $statusBadges[$deposit->status->value] ?? 'bg-slate-100 text-slate-700' }}">
                                    {{ $deposit->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-500 whitespace-nowrap">
                                {{ $deposit->detected_at?->format('Y-m-d H:i') ?? '—' }}
                                @if ($deposit->credited_at)
                                    <span class="block text-emerald-600">credited {{ $deposit->credited_at->format('Y-m-d H:i') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">
        {{ $deposits->links() }}
    </div>
@endsection
