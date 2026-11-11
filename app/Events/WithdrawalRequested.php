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
 * New withdrawal request — pushes into the admin dashboard feed live.
 */
class WithdrawalRequested implements ShouldBroadcast
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
        return 'withdrawal.requested';
    }

    public function broadcastWith(): array
    {
        return $this->withdrawal->only([
            'id', 'user_id', 'asset', 'chain', 'amount', 'fee', 'to_address', 'status',
        ]) + ['created_at' => optional($this->withdrawal->created_at)->toIso8601String()];
    }
}
