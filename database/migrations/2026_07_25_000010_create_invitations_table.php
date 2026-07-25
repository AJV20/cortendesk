<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending user invitations (PLAN D1).
 *
 * The token is stored hashed and the granted privileges live here, server-side,
 * so the accept URL carries nothing but an opaque random string — a recipient
 * cannot promote themselves by editing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('username');
            $table->string('name')->nullable();
            // sha256 of the plaintext token; the plaintext is shown once.
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_admin')->default(false);
            $table->json('user_group_ids')->nullable();
            $table->json('device_group_ids')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // Stored, not derived: auditable, and a future per-install expiry
            // setting can change without retroactively voiding live invites.
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
