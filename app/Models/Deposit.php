<?php

namespace App\Models;

use App\Enums\Chain;
use App\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'chain',
        'asset',
        'tx_hash',
        'log_index',
        'from_address',
        'to_address',
        'amount',
        'block_number',
        'confirmations',
        'required_confirmations',
        'status',
        'detected_at',
        'credited_at',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'chain' => Chain::class,
            'status' => DepositStatus::class,
            'confirmations' => 'integer',
            'required_confirmations' => 'integer',
            'block_number' => 'integer',
            'detected_at' => 'datetime',
            'credited_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isCredited(): bool
    {
        return $this->status === DepositStatus::Credited;
    }

    public function progress(): int
    {
        if ($this->required_confirmations <= 0) {
            return 100;
        }

        return (int) min(100, round($this->confirmations / $this->required_confirmations * 100));
    }
}
