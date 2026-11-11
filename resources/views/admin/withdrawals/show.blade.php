@extends('admin.layout')

@section('title', "Withdrawal #{$withdrawal->id}")

@section('content')
    @php use App\Support\Decimal; @endphp

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-semibold">
            Withdrawal <span class="font-mono">#{{ $withdrawal->id }}</span>
            <span class="ml-2 rounded px-2 py-0.5 text-xs align-middle {{ $withdrawal->status->badgeClass() }}">
                {{ $withdrawal->status->label() }}
            </span>
        </h1>
        <a href="{{ route('admin.withdrawals.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; All withdrawals</a>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">

        {{-- ----------------------------------------------------- main details --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h2 class="font-medium mb-4">Details</h2>
                <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Amount</dt>
                        <dd class="font-mono text-base">{{ Decimal::prettify($withdrawal->amount) }} {{ $withdrawal->asset }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Fee</dt>
                        <dd class="font-mono">{{ Decimal::prettify($withdrawal->fee) }} {{ $withdrawal->asset }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Total locked</dt>
                        <dd class="font-mono font-medium">{{ Decimal::prettify($withdrawal->totalLocked()) }} {{ $withdrawal->asset }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Rail / approval</dt>
                        <dd>{{ $withdrawal->rail }} · {{ $withdrawal->approval_type }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Chain</dt>
                        <dd>{{ $withdrawal->chain?->label() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Requested</dt>
                        <dd>{{ $withdrawal->created_at->format('Y-m-d H:i:s') }} ({{ $withdrawal->requested_ip }})</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase text-slate-500">Destination address</dt>
                        <dd class="font-mono break-all bg-slate-50 rounded px-2 py-1.5">{{ $withdrawal->to_address }}</dd>
                    </div>
                    @if ($withdrawal->memo)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase text-slate-500">Memo / tag</dt>
                            <dd class="font-mono break-all">{{ $withdrawal->memo }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- ------------------------------------------------- lifecycle info --}}
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h2 class="font-medium mb-4">Lifecycle</h2>
                <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Approved</dt>
                        <dd>
                            @if ($withdrawal->approved_at)
                                {{ $withdrawal->approved_at->format('Y-m-d H:i') }}
                                <span class="text-slate-500">by {{ $withdrawal->approver?->name ?? 'system' }}</span>
                            @else —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Rejected</dt>
                        <dd>
                            @if ($withdrawal->rejected_at)
                                {{ $withdrawal->rejected_at->format('Y-m-d H:i') }}
                                <span class="text-slate-500">by {{ $withdrawal->rejector?->name }}</span>
                                <span class="block text-xs text-rose-600">{{ $withdrawal->reject_reason }}</span>
                            @else —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Provider</dt>
                        <dd>
                            {{ $withdrawal->provider ?? '—' }}
                            @if ($withdrawal->provider_withdraw_id)
                                <span class="block font-mono text-xs text-slate-500">id: {{ $withdrawal->provider_withdraw_id }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Attempts</dt>
                        <dd>{{ $withdrawal->attempts }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase text-slate-500">On-chain tx</dt>
                        <dd>
                            @if ($withdrawal->tx_hash)
                                @if ($withdrawal->explorerUrl())
                                    <a href="{{ $withdrawal->explorerUrl() }}" target="_blank" rel="noopener"
                                       class="font-mono text-xs text-indigo-600 hover:underline break-all">{{ $withdrawal->tx_hash }}</a>
                                @else
                                    <span class="font-mono text-xs break-all">{{ $withdrawal->tx_hash }}</span>
                                @endif
                            @else —
                            @endif
                        </dd>
                    </div>
                    @if ($withdrawal->failure_reason)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase text-slate-500">Failure reason</dt>
                            <dd class="text-rose-700">{{ $withdrawal->failure_reason }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Processed</dt>
                        <dd>{{ $withdrawal->processed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-slate-500">Completed</dt>
                        <dd>{{ $withdrawal->completed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- --------------------------------------------------------- sidebar --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h2 class="font-medium mb-4">User</h2>
                <p class="text-sm font-medium">{{ $withdrawal->user?->name }}</p>
                <p class="text-xs text-slate-500 mb-3">{{ $withdrawal->user?->email }}</p>
                @if ($withdrawal->user)
                    <a href="{{ route('admin.users.show', $withdrawal->user) }}"
                       class="text-xs text-indigo-600 hover:underline">Open user profile &rarr;</a>
                @endif
            </div>

            {{-- --------------------------------------------- approve / reject --}}
            @if ($withdrawal->status->needsAdminAction())
                <div class="bg-white rounded-xl shadow-sm p-5 border border-amber-200">
                    <h2 class="font-medium mb-1">Decision required</h2>
                    <p class="text-xs text-slate-500 mb-4">
                        A fresh 2FA code from your authenticator app is required for both actions.
                    </p>

                    <form method="POST" action="{{ route('admin.withdrawals.approve', $withdrawal) }}" class="space-y-3 mb-5">
                        @csrf
                        <input type="text" name="two_factor_code" required inputmode="numeric" autocomplete="one-time-code"
                               placeholder="2FA code"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center tracking-widest text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                        <button class="w-full rounded-lg bg-emerald-600 text-white py-2 text-sm font-medium hover:bg-emerald-500">
                            Approve &amp; queue send
                        </button>
                    </form>

                    <hr class="border-slate-100 mb-4">

                    <form method="POST" action="{{ route('admin.withdrawals.reject', $withdrawal) }}" class="space-y-3">
                        @csrf
                        <textarea name="reason" required rows="2" maxlength="500" placeholder="Rejection reason (visible in audit log)"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"></textarea>
                        <input type="text" name="two_factor_code" required inputmode="numeric" autocomplete="one-time-code"
                               placeholder="2FA code"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center tracking-widest text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                        <button class="w-full rounded-lg bg-rose-600 text-white py-2 text-sm font-medium hover:bg-rose-500">
                            Reject &amp; unlock funds
                        </button>
                    </form>
                </div>
            @else
                <div class="bg-white rounded-xl shadow-sm p-5">
                    <h2 class="font-medium mb-2">No action available</h2>
                    <p class="text-xs text-slate-500">
                        Only withdrawals in <span class="font-medium">pending approval</span> can be approved or rejected.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endsection
