<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange API credentials. Keys/secrets are encrypted at rest and only
 * decrypted inside BinanceService / ExchangeManager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190)->unique();
            $table->string('exchange', 50)->index();
            $table->text('key');
            $table->text('secret');
            $table->json('options')->nullable();
            $table->string('allowed_ips')->nullable(); // documentation / audit only
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
