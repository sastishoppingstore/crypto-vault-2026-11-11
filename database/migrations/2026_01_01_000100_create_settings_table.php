<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime configuration (admin editable, cached in Redis).
 * Values are JSON encoded so ints/bools/arrays keep their type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 190)->unique();
            $table->json('value')->nullable();
            $table->string('group', 50)->default('general')->index();
            $table->string('type', 20)->default('string');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
