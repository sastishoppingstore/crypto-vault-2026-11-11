<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User withdrawal requests with the full approval + processing lifecycle.
 *
 * Status flow:
 *   pending_auto -> processing -> completed | failed
 *   pending_manual -> approved -> processing -> completed | failed
 *   pending_manual -> rejected (funds unlocked)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 20)->index();
            $table->string('chain', 20)->nullable();
            $table->string('rail', 20)->default('binance'); // binance | onchain
            $table->decimal('amount', 36, 18);              // amount sent to user
            $table->decimal('fee', 36, 18)->default(0);     // network/service fee
            $table->decimal('total', 36, 18);               // amount + fee (locked)
            $table->string('to_address', 128);
            $table->string('memo', 128)->nullable();
            $table->string('status', 30)->default('pending_manual')->index();
            $table->string('approval_type', 10)->nullable(); // auto | manual
            $table->string('requested_ip', 45)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('provider', 30)->nullable();      // binance | evm | ...
            $table->string('provider_withdraw_id', 128)->nullable();
            $table->string('tx_hash', 128)->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('tx_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
