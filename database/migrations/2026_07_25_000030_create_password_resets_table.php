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
            // NOT NULL timestamps must carry an explicit default. MariaDB and any
            // MySQL with explicit_defaults_for_timestamp=0 give a NOT NULL
            // TIMESTAMP an implicit '0000-00-00 00:00:00' unless it is the
            // table's FIRST timestamp column, and Laravel's strict mode adds
            // NO_ZERO_DATE, which then rejects the CREATE TABLE outright:
            //   SQLSTATE[42000]: 1067 Invalid default value for 'expires_at'
            // useCurrent() is the portable fix; every row sets the real value
            // on insert, so the default is never the one that survives.
            $table->timestamp('expires_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
