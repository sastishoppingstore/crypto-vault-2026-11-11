<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (Laravel Reverb / Pusher)
|--------------------------------------------------------------------------
| Auth endpoint: POST /broadcasting/auth with a Sanctum bearer token.
*/

// Per-user private channel: balance + withdrawal updates (vault.channels.user)
Broadcast::channel((string) config('vault.channels.user'), function ($user, string $userId) {
    return (int) $user->id === (int) $userId;
});

// Admin feed: new withdrawal requests and deposit events (vault.channels.admin)
Broadcast::channel((string) config('vault.channels.admin'), function ($user) {
    return (bool) $user?->isAdmin();
});
