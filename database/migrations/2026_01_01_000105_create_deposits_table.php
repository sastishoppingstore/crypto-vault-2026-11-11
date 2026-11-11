<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming on-chain transfers detected by the scanners.
 * The unique index makes deposit detection idempotent — rescanning the same
 * block/address can never double credit a deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('chain', 20);
            $table->string('asset', 20);
            $table->string('tx_hash', 128);
            $table->unsignedInteger('log_index')->default(0); // vout / log index
            $table->string('from_address', 128)->nullable();
            $table->string('to_address', 128);
            $table->decimal('amount', 36, 18);
            $table->unsignedBigInteger('block_number')->nullable();
            $table->unsignedInteger('confirmations')->default(0);
            $table->unsignedInteger('required_confirmations')->default(1);
            $table->string('status', 20)->default('detected')->index();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['chain', 'tx_hash', 'log_index', 'asset']);
            $table->index(['status', 'chain']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
