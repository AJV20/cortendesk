<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending RustDesk-client SSO authorizations (PLAN D3, client half).
 *
 * The client posts to /api/oidc/auth, opens the returned URL in the system
 * browser, then polls /api/oidc/auth-query. Those are three separate HTTP
 * conversations — two from the app with no cookies, one from a browser — so
 * the in-flight authorization cannot live in a session. It lives here.
 *
 * `code` is what the polling app presents; `state` is what the IdP hands back
 * to the browser. Both are random and unique, and the poll additionally has to
 * match the device id + uuid that started the flow, so one device cannot
 * collect another's token by guessing a code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_auth_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('state', 64)->unique();
            $table->string('nonce', 64);
            $table->string('verifier', 128);
            $table->string('op', 64)->nullable();

            // Bound at /api/oidc/auth and re-checked on every poll.
            $table->string('device_id')->nullable();
            $table->string('device_uuid')->nullable();
            $table->string('device_os')->nullable();
            $table->string('device_name')->nullable();
            $table->string('client_type')->nullable();

            // Filled once the browser half completes.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('access_token', 64)->nullable();
            $table->string('failure')->nullable();
            $table->timestamp('authorized_at')->nullable();

            // The client polls for 180s; rows outlive that only long enough to
            // report a result, and are pruned with the other expiring data.
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
        Schema::dropIfExists('oidc_auth_requests');
    }
};
