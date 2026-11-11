@extends('admin.layout')

@section('title', 'Users')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="text-lg font-semibold">Users</h1>

        <form method="GET" action="{{ route('admin.users.index') }}" class="flex gap-2">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search name or email…"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-slate-400">
            <button class="rounded-lg bg-slate-900 text-white text-sm px-4 py-1.5 hover:bg-slate-700">Search</button>
            @if ($search !== '')
                <a href="{{ route('admin.users.index') }}"
                   class="rounded-lg border border-slate-300 text-sm px-3 py-1.5 text-slate-600 hover:border-slate-400">Clear</a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        @if ($users->isEmpty())
            <p class="px-4 py-8 text-sm text-slate-500">No users found.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500 border-b border-slate-100 bg-slate-50">
                        <th class="px-4 py-2.5">#</th>
                        <th class="px-4 py-2.5">Name</th>
                        <th class="px-4 py-2.5">Email</th>
                        <th class="px-4 py-2.5">Role</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">2FA</th>
                        <th class="px-4 py-2.5">Registered</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 font-mono text-xs">{{ $user->id }}</td>
                            <td class="px-4 py-2.5 font-medium">{{ $user->name }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $user->email }}</td>
                            <td class="px-4 py-2.5">{{ $user->role->value }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded px-2 py-0.5 text-xs {{ $user->isActive() ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                    {{ $user->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-xs {{ $user->hasTwoFactorEnabled() ? 'text-emerald-700' : 'text-slate-400' }}">
                                {{ $user->hasTwoFactorEnabled() ? 'On' : 'Off' }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-500 whitespace-nowrap">{{ $user->created_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('admin.users.show', $user) }}"
                                   class="rounded bg-slate-900 text-white text-xs px-3 py-1.5 hover:bg-slate-700">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
@endsection
