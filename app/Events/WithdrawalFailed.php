<?php

namespace App\Events;

use App\Models\Withdrawal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Withdrawal failed (or was rejected) — funds were unlocked. */
class WithdrawalFailed implements ShouldBroadcast
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
        return 'withdrawal.failed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->withdrawal->id,
            'asset' => $this->withdrawal->asset,
            'amount' => $this->withdrawal->amount,
            'status' => $this->withdrawal->status->value,
            'reason' => $this->withdrawal->failure_reason ?? $this->withdrawal->reject_reason,
            'at' => now()->toIso8601String(),
        ];
    }
}
