@extends('admin.layout')

@section('title', 'Login')

@section('content')
    <div class="max-w-sm mx-auto mt-16">
        <div class="bg-white rounded-xl shadow p-8">
            <h1 class="text-xl font-semibold mb-6">Admin sign in</h1>

            <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <div>
                    <label class="block text-sm mb-1">Password</label>
                    <input type="password" name="password" required
                           class="w-full rounded-lg border-slate-300 border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-700">
                    Continue
                </button>
            </form>

            <p class="mt-6 text-xs text-slate-400">Admin access requires two-factor authentication.</p>
        </div>
    </div>
@endsection
