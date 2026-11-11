<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal ledger balances per user and asset.
 * available = spendable, locked = frozen by pending withdrawals.
 * All mutations happen inside DB transactions with row locks
 * (see App\Services\BalanceService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 20);
            $table->decimal('available', 36, 18)->default(0);
            $table->decimal('locked', 36, 18)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'asset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
