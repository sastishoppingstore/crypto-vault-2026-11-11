<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable, append-only ledger. Every balance mutation writes one row.
 * amount is signed: credit = positive, debit/lock = negative.
 * The unique reference index guarantees a business event can only ever be
 * applied to the ledger once (idempotency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index(); // App\Enums\TransactionType
            $table->string('asset', 20)->index();
            $table->decimal('amount', 36, 18);           // signed
            $table->decimal('balance_after', 36, 18)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['reference_type', 'reference_id', 'type', 'asset'], 'tx_reference_unique');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
