<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browsers that have already cleared an emailed sign-in code (PLAN D1).
 *
 * A server-issued opaque cookie, not a user-agent/IP fingerprint: UA strings
 * change on every browser update and IPs change constantly, so a fingerprint
 * would demand a fresh code on most sign-ins while adding no security (both
 * values are attacker-supplied headers). A random token is a real bearer
 * credential and is individually revocable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
