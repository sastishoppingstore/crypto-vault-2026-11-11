<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') — {{ config('app.name') }} Vault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

<nav class="bg-slate-900 text-slate-100">
    <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center gap-x-6 gap-y-2">
        <a href="{{ route('admin.dashboard') }}" class="font-semibold tracking-wide">⛓ {{ config('app.name') }} Vault</a>

        @auth
            <a href="{{ route('admin.dashboard') }}" class="text-sm hover:text-white {{ request()->routeIs('admin.dashboard') ? 'text-white font-medium' : 'text-slate-300' }}">Dashboard</a>
            <a href="{{ route('admin.withdrawals.index') }}" class="text-sm hover:text-white {{ request()->routeIs('admin.withdrawals*') ? 'text-white font-medium' : 'text-slate-300' }}">Withdrawals</a>
            <a href="{{ route('admin.deposits.index') }}" class="text-sm hover:text-white {{ request()->routeIs('admin.deposits*') ? 'text-white font-medium' : 'text-slate-300' }}">Deposits</a>
            <a href="{{ route('admin.users.index') }}" class="text-sm hover:text-white {{ request()->routeIs('admin.users*') ? 'text-white font-medium' : 'text-slate-300' }}">Users</a>
            <a href="{{ route('admin.settings.index') }}" class="text-sm hover:text-white {{ request()->routeIs('admin.settings*') ? 'text-white font-medium' : 'text-slate-300' }}">Settings</a>
            <a href="{{ route('admin.logs.index') }}" class="text-sm hover:text-white {{ request()->routeIs('admin.logs*') ? 'text-white font-medium' : 'text-slate-300' }}">Audit log</a>

            <div class="ml-auto flex items-center gap-4">
                <span class="text-xs text-slate-400">{{ auth()->user()->email }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="text-xs rounded bg-slate-700 px-3 py-1.5 hover:bg-slate-600">Logout</button>
                </form>
            </div>
        @endauth
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 py-8">
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-100 text-emerald-900 px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-rose-100 text-rose-900 px-4 py-2 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
