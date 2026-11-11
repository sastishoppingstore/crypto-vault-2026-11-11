@extends('admin.layout')

@section('title', 'Enable two-factor authentication')

@section('content')
    <div class="max-w-md mx-auto mt-10">
        <div class="bg-white rounded-xl shadow p-8">
            <h1 class="text-xl font-semibold mb-2">Enable two-factor authentication</h1>
            <p class="text-sm text-slate-500 mb-6">
                2FA is mandatory for admins. Scan the QR code with Google Authenticator / Authy, then confirm with the first code.
            </p>

            <div class="flex justify-center mb-4">
                {!! $qrSvg !!}
            </div>

            <p class="text-center text-xs text-slate-500 mb-1">Manual setup key:</p>
            <p class="text-center font-mono text-sm bg-slate-100 rounded px-2 py-1 mb-6 break-all">{{ $secret }}</p>

            <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="space-y-4">
                @csrf

                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required
                       class="w-full rounded-lg border-slate-300 border px-3 py-3 text-center text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-slate-400"
                       placeholder="••••••">

                <button class="w-full rounded-lg bg-slate-900 text-white py-2.5 text-sm font-medium hover:bg-slate-700">
                    Confirm & enable
                </button>
            </form>
        </div>
    </div>
@endsection
