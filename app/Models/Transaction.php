<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only ledger row. Never update or delete rows manually.
 *
 * `amount` is the signed change of the user's *available* balance.
 * WithdrawalDebit rows settle the locked amount (available change = 0);
 * the locked delta lives in `meta.locked_delta`.
 */
class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'asset',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<Transaction>  $query */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    /** @param  Builder<Transaction>  $query */
    public function scopeCredits(Builder $query): void
    {
        $query->where('amount', '>', 0);
    }
}
