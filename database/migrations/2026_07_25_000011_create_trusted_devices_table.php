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
        Schema::dropIfExists('trusted_devices');
    }
};
