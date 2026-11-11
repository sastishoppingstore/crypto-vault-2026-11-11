<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polling cursors, e.g.:
 *   evm.eth.block      -> last scanned block number
 *   bitcoin.tip        -> informational
 *   binance.deposits   -> last deposit history timestamp (ms)
 *   binance.withdrawals-> last withdrawal history timestamp (ms)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('key', 190)->unique();
            $table->string('position', 190)->default('0');
            $table->json('meta')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
