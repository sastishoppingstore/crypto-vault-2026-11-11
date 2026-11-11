<?php

namespace App\Events;

use App\Support\Decimal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live balance update for the user dashboard.
 * Channel: private-user.{userId}  Event: balance.updated
 */
class BalanceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $asset,
        public readonly string $available,
        public readonly string $locked,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(str_replace('{userId}', (string) $this->userId, (string) config('vault.channels.user')))];
    }

    public function broadcastAs(): string
    {
        return 'balance.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'asset' => $this->asset,
            'available' => Decimal::prettify($this->available),
            'locked' => Decimal::prettify($this->locked),
            'total' => Decimal::prettify(Decimal::add($this->available, $this->locked)),
            'at' => now()->toIso8601String(),
        ];
    }
}
