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
            // NOT NULL timestamps must carry an explicit default. MariaDB and any
            // MySQL with explicit_defaults_for_timestamp=0 give a NOT NULL
            // TIMESTAMP an implicit '0000-00-00 00:00:00' unless it is the
            // table's FIRST timestamp column, and Laravel's strict mode adds
            // NO_ZERO_DATE, which then rejects the CREATE TABLE outright:
            //   SQLSTATE[42000]: 1067 Invalid default value for 'expires_at'
            // useCurrent() is the portable fix; every row sets the real value
            // on insert, so the default is never the one that survives.
            $table->timestamp('expires_at')->useCurrent();
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
