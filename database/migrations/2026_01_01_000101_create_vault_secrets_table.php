<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted vault secrets: HD wallet mnemonic, hot wallet private keys, etc.
 * Values are AES-256-GCM ciphertext (App\Services\Security\EncryptionService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190)->unique();
            $table->text('value');
            $table->json('meta')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_secrets');
    }
};
