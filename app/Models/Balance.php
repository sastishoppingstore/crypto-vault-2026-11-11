<?php

namespace App\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal ledger balance. All mutations go through BalanceService
 * (row-locked inside a DB transaction, ledger row appended).
 */
class Balance extends Model
{
    protected $fillable = [
        'user_id',
        'asset',
        'available',
        'locked',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function total(): string
    {
        return Decimal::add($this->available, $this->locked);
    }
}
