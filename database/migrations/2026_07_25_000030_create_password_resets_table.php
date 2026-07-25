<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service password reset.
 *
 * Same shape as `invitations`: the token is stored as a sha256 hash so a leaked
 * database row cannot be replayed as a reset link, and the row carries its own
 * single-use marker and expiry rather than relying on Laravel's broker.
 *
 * Not Laravel's `password_reset_tokens` table: that keys on the email address,
 * which would break for the accounts here that have none, and it has no
 * used-at column, so a link stays live for its whole window even after use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('requested_ip')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
