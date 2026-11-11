<?php

namespace App\Events;

use App\Models\Deposit;
use App\Support\Decimal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A deposit reached its confirmation threshold and was credited.
 * Channels: private-user.{userId} + private-admin.feed
 */
class DepositCredited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Deposit $deposit)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(str_replace('{userId}', (string) $this->deposit->user_id, (string) config('vault.channels.user'))),
            new PrivateChannel((string) config('vault.channels.admin')),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deposit.credited';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->deposit->id,
            'user_id' => $this->deposit->user_id,
            'chain' => $this->deposit->chain->value,
            'asset' => $this->deposit->asset,
            'amount' => Decimal::prettify($this->deposit->amount),
            'tx_hash' => $this->deposit->tx_hash,
            'confirmations' => $this->deposit->confirmations,
            'credited_at' => optional($this->deposit->credited_at)->toIso8601String(),
        ];
    }
}
