<?php

namespace App\Models;

use App\Enums\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-user deposit address on a specific chain.
 * Private keys are NEVER stored — they are derived on demand from the
 * encrypted HD seed using (derivation_path, address_index).
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'chain',
        'address',
        'derivation_path',
        'address_index',
        'label',
        'is_active',
        'last_activity_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'chain' => Chain::class,
            'is_active' => 'boolean',
            'last_activity_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /** Assets (native + tokens) accepted on this chain. */
    public function acceptedAssets(): array
    {
        return $this->chain->assets();
    }
}
