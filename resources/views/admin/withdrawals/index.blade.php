@extends('admin.layout')

@section('title', 'Withdrawals')

@section('content')
    @php use App\Support\Decimal; @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="text-lg font-semibold">
            Withdrawals
            @if ($pendingCount > 0)
                <span class="ml-2 rounded-full bg-amber-100 text-amber-800 text-xs px-2.5 py-1">{{ $pendingCount }} pending</span>
            @endif
        </h1>

        {{-- Status filter tabs --}}
        <div class="flex flex-wrap gap-1 text-xs">
            <a href="{{ route('admin.withdrawals.index') }}"
               class="rounded-full px-3 py-1.5 border {{ $status === null || $status === '' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-400' }}">
                All
            </a>
            @foreach ($statuses as $case)
                <a href="{{ route('admin.withdrawals.index', ['status' => $case->value]) }}"
                   class="rounded-full px-3 py-1.5 border {{ $status === $case->value ? 'bg-slate-900 text-white border-slate-900' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-400' }}">
                    {{ $case->label() }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        @if ($withdrawals->isEmpty())
            <p class="px-4 py-8 text-sm text-slate-500">No withdrawals found.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100 bg-slate-50">
                        <th class="px-4 py-2.5">#</th>
                        <th class="px-4 py-2.5">User</th>
                        <th class="px-4 py-2.5">Asset / rail</th>
                        <th class="px-4 py-2.5 text-right">Amount</th>
                        <th class="px-4 py-2.5 text-right">Fee</th>
                        <th class="px-4 py-2.5 text-right">Total locked</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">Requested</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($withdrawals as $withdrawal)
                        <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono text-xs">{{ $withdrawal->id }}</td>
                            <td class="px-4 py-2.5">
                                {{ $withdrawal->user?->name }}
                                <span class="block text-xs text-slate-400">{{ $withdrawal->user?->email }}</span>
                            </td>
                            <td class="px-4 py-2.5">
                                {{ $withdrawal->asset }}
                                <span class="block text-xs text-slate-400">
                                    {{ $withdrawal->chain?->label() ?? '—' }} · {{ $withdrawal->rail }}
                                    · {{ $withdrawal->approval_type }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ Decimal::prettify($withdrawal->amount) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-slate-500">{{ Decimal::prettify($withdrawal->fee) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ Decimal::prettify($withdrawal->totalLocked()) }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded px-2 py-0.5 text-xs whitespace-nowrap {{ $withdrawal->status->badgeClass() }}">
                                    {{ $withdrawal->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-500 whitespace-nowrap">{{ $withdrawal->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('admin.withdrawals.show', $withdrawal) }}"
                                   class="rounded bg-slate-900 text-white text-xs px-3 py-1.5 hover:bg-slate-700">
                                    {{ $withdrawal->status->needsAdminAction() ? 'Review' : 'Open' }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">
        {{ $withdrawals->links() }}
    </div>
@endsection
