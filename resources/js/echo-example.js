/**
 * Crypto Vault — Laravel Echo client example (Reverb / Pusher protocol).
 *
 * Install in your frontend:
 *   npm i laravel-echo pusher-js
 *
 * Include this file (adapt imports to your bundler) after the user has
 * logged in on the API and you hold a Sanctum bearer token.
 *
 * Channels & events:
 *   private user.{id}   ->  .balance.updated      (BalanceUpdated)
 *                       ->  .withdrawal.status    (WithdrawalStatusChanged)
 *   private admin.feed  ->  .withdrawal.requested / .withdrawal.status / .deposit.credited
 *                          (admins only)
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            // The broadcasting auth route is guarded by auth:sanctum.
            Authorization: `Bearer ${sanctumToken}`,
            Accept: 'application/json',
        },
    },
});

/**
 * Subscribe to the logged-in user's private channel.
 * @param {number} userId   the authenticated user's id
 */
export function subscribeUserChannel(userId) {
    const channel = window.Echo.private(`user.${userId}`);

    channel.listen('.balance.updated', (payload) => {
        // payload: { asset, available, locked, total, at }
        console.log('[balance]', payload.asset, payload.available, 'locked:', payload.locked);
        // e.g. update a reactive store / DOM node here
    });

    channel.listen('.withdrawal.status', (payload) => {
        // payload: { id, asset, amount, status, status_label, tx_hash, explorer_url, failure_reason, at }
        console.log('[withdrawal]', payload.id, payload.status, payload.tx_hash ?? '');
    });

    return channel;
}

/**
 * Admins: live feed of new requests + transitions across the whole platform.
 * Requires the authenticated user to have role = admin.
 */
export function subscribeAdminFeed() {
    const channel = window.Echo.private('admin.feed');

    channel.listen('.withdrawal.requested', (payload) => {
        console.log('[admin] new withdrawal request', payload);
    });

    channel.listen('.deposit.credited', (payload) => {
        console.log('[admin] deposit credited', payload);
    });

    return channel;
}

// Cleanup on logout:
//   window.Echo.leave(`user.${userId}`);
//   window.Echo.leave('admin.feed');
