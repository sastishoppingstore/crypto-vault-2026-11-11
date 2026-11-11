@extends('admin.layout')

@section('title', 'Two-factor verification')

@section('content')
    <div class="max-w-sm mx-auto mt-16">
        <div class="bg-white rounded-xl shadow p-8">
            <h1 class="text-xl font-semibold mb-2">Two-factor verification</h1>
            <p class="text-sm text-slate-500 mb-6">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

            <form method="POST" action="{{ route('admin.2fa.verify') }}" class="space-y-4">
                @csrf

                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus
                       class="w-full rounded-lg border-slate-300 border px-3 py-3 text-center text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-slate-400"
                       placeholder="••••••">

                <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-700">
                    Verify
                </button>
            </form>
        </div>
    </div>
@endsection
