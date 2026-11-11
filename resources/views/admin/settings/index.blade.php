@extends('admin.layout')

@section('title', 'Settings')

@section('content')
    <h1 class="text-lg font-semibold mb-2">Withdrawal &amp; deposit settings</h1>
    <p class="text-sm text-slate-500 mb-6">
        Runtime policy — takes effect immediately (cached). Exchange API keys and the HD seed are
        <span class="font-medium">not</span> editable here; they live encrypted in the database and are managed with console commands.
    </p>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6 max-w-4xl">
        @csrf

        {{-- ---------------------------------------------------- auto approval --}}
        <div class="bg-white rounded-xl shadow-sm p-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="auto_enabled" value="1" @checked($autoEnabled)
                       class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                <span>
                    <span class="font-medium">Automatic withdrawal processing</span>
                    <span class="block text-xs text-slate-500">
                        When enabled, requests at or below each asset's <span class="font-mono">auto_limit</span>
                        are queued automatically; larger requests always wait for manual approval.
                    </span>
                </span>
            </label>
        </div>

        {{-- --------------------------------------------------- withdrawal assets --}}
        <div class="bg-white rounded-xl shadow-sm p-5">
            <h2 class="font-medium mb-1">Withdrawal assets</h2>
            <p class="text-xs text-slate-500 mb-3">
                JSON object keyed by asset. Each entry requires:
                <span class="font-mono">enabled</span> (bool),
                <span class="font-mono">rail</span> (<span class="font-mono">binance</span> | <span class="font-mono">onchain</span>),
                <span class="font-mono">min</span>,
                <span class="font-mono">fee</span>,
                <span class="font-mono">auto_limit</span> (decimal strings).
            </p>
            <textarea name="assets_json" rows="16" required spellcheck="false"
                      class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs leading-relaxed focus:outline-none focus:ring-2 focus:ring-slate-400">{{ json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        </div>

        {{-- ------------------------------------------------------- confirmations --}}
        <div class="bg-white rounded-xl shadow-sm p-5">
            <h2 class="font-medium mb-1">Deposit confirmations</h2>
            <p class="text-xs text-slate-500 mb-3">
                JSON object keyed by chain (<span class="font-mono">btc</span>, <span class="font-mono">eth</span>,
                <span class="font-mono">bsc</span>, <span class="font-mono">polygon</span>, <span class="font-mono">tron</span>)
                mapping to the number of confirmations required before crediting.
            </p>
            <textarea name="confirmations_json" rows="8" required spellcheck="false"
                      class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs leading-relaxed focus:outline-none focus:ring-2 focus:ring-slate-400">{{ json_encode($confirmations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        </div>

        <div class="flex items-center gap-3">
            <button class="rounded-lg bg-slate-900 text-white px-6 py-2.5 text-sm font-medium hover:bg-slate-700">
                Save settings
            </button>
            <span class="text-xs text-slate-500">Most changes are reflected within the cache TTL (seconds).</span>
        </div>
    </form>
@endsection
