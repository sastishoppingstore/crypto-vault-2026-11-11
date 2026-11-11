<?php

namespace App\Events;

use App\Models\Withdrawal;
use App\Support\Decimal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Every withdrawal state transition (approved / processing / rejected / ...).
 * Keeps user dashboards and the admin feed in sync in real time.
 */
class WithdrawalStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Withdrawal $withdrawal)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(str_replace('{userId}', (string) $this->withdrawal->user_id, (string) config('vault.channels.user'))),
            new PrivateChannel((string) config('vault.channels.admin')),
        ];
    }

    public function broadcastAs(): string
    {
        return 'withdrawal.status';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->withdrawal->id,
            'user_id' => $this->withdrawal->user_id,
            'asset' => $this->withdrawal->asset,
            'amount' => Decimal::prettify($this->withdrawal->amount),
            'status' => $this->withdrawal->status->value,
            'status_label' => $this->withdrawal->status->label(),
            'tx_hash' => $this->withdrawal->tx_hash,
            'explorer_url' => $this->withdrawal->explorerUrl(),
            'failure_reason' => $this->withdrawal->failure_reason,
            'at' => now()->toIso8601String(),
        ];
    }
}
