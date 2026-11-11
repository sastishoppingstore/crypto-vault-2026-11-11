<?php

namespace App\Events;

use App\Models\Withdrawal;
use App\Support\Decimal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Withdrawal delivered successfully. Hook mail/Telegram notifications here. */
class WithdrawalCompleted implements ShouldBroadcast
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
        return 'withdrawal.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->withdrawal->id,
            'asset' => $this->withdrawal->asset,
            'amount' => Decimal::prettify($this->withdrawal->amount),
            'tx_hash' => $this->withdrawal->tx_hash,
            'explorer_url' => $this->withdrawal->explorerUrl(),
            'completed_at' => optional($this->withdrawal->completed_at)->toIso8601String(),
        ];
    }
}
