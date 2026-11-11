<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user deposit addresses (HD derived, private keys are never stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chain', 20)->index();
            $table->string('address', 128);
            $table->string('derivation_path', 64);
            $table->unsignedInteger('address_index');
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_activity_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['chain', 'address']);
            $table->unique(['chain', 'address_index']);
            $table->index(['user_id', 'chain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
