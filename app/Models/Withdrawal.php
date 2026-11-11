<?php

namespace App\Models;

use App\Enums\Chain;
use App\Enums\WithdrawalStatus;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'asset',
        'chain',
        'rail',
        'amount',
        'fee',
        'total',
        'to_address',
        'memo',
        'status',
        'approval_type',
        'requested_ip',
        'idempotency_key',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'provider',
        'provider_withdraw_id',
        'tx_hash',
        'failure_reason',
        'attempts',
        'processed_at',
        'completed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'chain' => Chain::class,
            'status' => WithdrawalStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
            'attempts' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // ------------------------------------------------------------------- scopes

    /** @param  Builder<Withdrawal>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', WithdrawalStatus::PendingManual);
    }

    /** @param  Builder<Withdrawal>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            WithdrawalStatus::PendingAuto,
            WithdrawalStatus::PendingManual,
            WithdrawalStatus::Approved,
            WithdrawalStatus::Processing,
        ]);
    }

    /** @param  Builder<Withdrawal>  $query */
    public function scopeProcessing(Builder $query): void
    {
        $query->where('status', WithdrawalStatus::Processing);
    }

    // ------------------------------------------------------------------ helpers

    public function explorerUrl(): ?string
    {
        if (! $this->tx_hash || ! $this->chain) {
            return null;
        }

        return $this->chain->config('explorer_tx_url')
            ? rtrim((string) $this->chain->config('explorer_tx_url'), '/').'/'.$this->tx_hash
            : null;
    }

    public function totalLocked(): string
    {
        return Decimal::add($this->amount, $this->fee);
    }
}
