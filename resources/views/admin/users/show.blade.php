@extends('admin.layout')

@section('title', "User: {$user->name}")

@section('content')
    @php use App\Support\Decimal; @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-lg font-semibold">
                {{ $user->name }}
                <span class="ml-1 rounded px-2 py-0.5 text-xs align-middle {{ $user->isActive() ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                    {{ $user->status->label() }}
                </span>
            </h1>
            <p class="text-sm text-slate-500">
                {{ $user->email }} · {{ $user->role->value }} · joined {{ $user->created_at->format('Y-m-d') }}
                @if ($user->frozen_at)
                    · frozen {{ $user->frozen_at->format('Y-m-d H:i') }}
                @endif
            </p>
        </div>
        <a href="{{ route('admin.users.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; All users</a>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">

        {{-- ----------------------------------------------------------- left --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- balances --}}
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="font-medium">Balances</h2>
                </div>
                @if ($user->balances->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No balances.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                                <th class="px-5 py-2">Asset</th>
                                <th class="px-5 py-2 text-right">Available</th>
                                <th class="px-5 py-2 text-right">Locked</th>
                                <th class="px-5 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($user->balances as $balance)
                                <tr class="border-b border-slate-50 last:border-0">
                                    <td class="px-5 py-2 font-medium">{{ $balance->asset }}</td>
                                    <td class="px-5 py-2 text-right font-mono">{{ Decimal::prettify($balance->available) }}</td>
                                    <td class="px-5 py-2 text-right font-mono text-slate-500">{{ Decimal::prettify($balance->locked) }}</td>
                                    <td class="px-5 py-2 text-right font-mono font-medium">{{ Decimal::prettify($balance->total()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- wallets --}}
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="font-medium">Deposit addresses</h2>
                </div>
                @if ($user->wallets->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No wallets generated yet.</p>
                @else
                    <ul class="divide-y divide-slate-50">
                        @foreach ($user->wallets as $wallet)
                            <li class="px-5 py-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                <span class="w-20 font-medium">{{ $wallet->chain?->label() }}</span>
                                <span class="font-mono text-xs break-all">{{ $wallet->address }}</span>
                                <span class="ml-auto font-mono text-xs text-slate-400">{{ $wallet->derivation_path }}</span>
                                <span class="rounded px-2 py-0.5 text-xs {{ $wallet->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $wallet->is_active ? 'active' : 'inactive' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- recent transactions --}}
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="font-medium">Recent ledger entries</h2>
                </div>
                @if ($user->transactions->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No transactions.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                                <th class="px-5 py-2">Type</th>
                                <th class="px-5 py-2 text-right">Amount</th>
                                <th class="px-5 py-2 text-right">Balance after</th>
                                <th class="px-5 py-2">Description</th>
                                <th class="px-5 py-2">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($user->transactions as $transaction)
                                <tr class="border-b border-slate-50 last:border-0">
                                    <td class="px-5 py-2 text-xs">{{ $transaction->type->value }}</td>
                                    <td class="px-5 py-2 text-right font-mono {{ str_starts_with($transaction->amount, '-') ? 'text-rose-600' : 'text-emerald-700' }}">
                                        {{ Decimal::prettify($transaction->amount) }} {{ $transaction->asset }}
                                    </td>
                                    <td class="px-5 py-2 text-right font-mono text-slate-500">{{ Decimal::prettify($transaction->balance_after) }}</td>
                                    <td class="px-5 py-2 text-xs text-slate-600">{{ $transaction->description }}</td>
                                    <td class="px-5 py-2 text-xs text-slate-500 whitespace-nowrap">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- recent withdrawals --}}
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="font-medium">Recent withdrawals</h2>
                </div>
                @if ($user->withdrawals->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No withdrawals.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100">
                                <th class="px-5 py-2">#</th>
                                <th class="px-5 py-2">Asset</th>
                                <th class="px-5 py-2 text-right">Total locked</th>
                                <th class="px-5 py-2">Status</th>
                                <th class="px-5 py-2">Date</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($user->withdrawals as $withdrawal)
                                <tr class="border-b border-slate-50 last:border-0">
                                    <td class="px-5 py-2 font-mono text-xs">{{ $withdrawal->id }}</td>
                                    <td class="px-5 py-2">{{ $withdrawal->asset }}</td>
                                    <td class="px-5 py-2 text-right font-mono">{{ Decimal::prettify($withdrawal->totalLocked()) }}</td>
                                    <td class="px-5 py-2">
                                        <span class="rounded px-2 py-0.5 text-xs {{ $withdrawal->status->badgeClass() }}">
                                            {{ $withdrawal->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-2 text-xs text-slate-500 whitespace-nowrap">{{ $withdrawal->created_at->format('Y-m-d H:i') }}</td>
                                    <td class="px-5 py-2 text-right">
                                        <a href="{{ route('admin.withdrawals.show', $withdrawal) }}" class="text-xs text-indigo-600 hover:underline">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- ---------------------------------------------------------- sidebar --}}
        <div class="space-y-6">

            {{-- adjust balance --}}
            <div class="bg-white rounded-xl shadow-sm p-5 border border-amber-200">
                <h2 class="font-medium mb-1">Adjust balance</h2>
                <p class="text-xs text-slate-500 mb-4">
                    Signed amount (negative to debit). Creates a ledger entry and is audit logged. Requires 2FA.
                </p>

                <form method="POST" action="{{ route('admin.users.balance', $user) }}" class="space-y-3">
                    @csrf
                    <input type="text" name="asset" required placeholder="Asset (e.g. USDT)" maxlength="20"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <input type="text" name="amount" required placeholder="Amount (e.g. 25.5 or -10)"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <input type="text" name="reason" required maxlength="255" placeholder="Reason (required)"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <input type="text" name="two_factor_code" required inputmode="numeric" autocomplete="one-time-code" placeholder="2FA code"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center tracking-widest text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <button class="w-full rounded-lg bg-amber-600 text-white py-2 text-sm font-medium hover:bg-amber-500">
                        Apply adjustment
                    </button>
                </form>
            </div>

            {{-- freeze / unfreeze --}}
            <div class="bg-white rounded-xl shadow-sm p-5 border {{ $user->isFrozen() ? 'border-emerald-200' : 'border-rose-200' }}">
                <h2 class="font-medium mb-1">{{ $user->isFrozen() ? 'Unfreeze user' : 'Freeze user' }}</h2>
                <p class="text-xs text-slate-500 mb-4">
                    @if ($user->isFrozen())
                        The user is currently frozen. Unfreezing restores withdrawals.
                    @else
                        Freezing disables withdrawals immediately. Deposits remain open.
                    @endif
                </p>

                <form method="POST" action="{{ route('admin.users.freeze', $user) }}" class="space-y-3">
                    @csrf
                    <input type="text" name="two_factor_code" required inputmode="numeric" autocomplete="one-time-code" placeholder="2FA code"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center tracking-widest text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <button class="w-full rounded-lg py-2 text-sm font-medium text-white {{ $user->isFrozen() ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500' }}">
                        {{ $user->isFrozen() ? 'Unfreeze' : 'Freeze' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
