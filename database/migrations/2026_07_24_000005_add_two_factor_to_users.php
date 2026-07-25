<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Console 2FA (PLAN B6). `users.totp_secret` already exists (stored encrypted
 * at rest via the model's `encrypted` cast). Add the enable/confirm flags and
 * the replay guard (`totp_last_timestep`), plus a separate single-use recovery
 * codes table (bcrypt hashes, one set of 10 per user).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The encrypted-at-rest secret (~370 chars) overflows the original
            // varchar(255); widen it to TEXT so it isn't silently truncated.
            $table->text('totp_secret')->nullable()->change();
            $table->boolean('totp_enabled')->default(false)->after('totp_secret');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_enabled');
            // Last accepted 30s timestep — reject any code whose timestep is
            // less than or equal to this one (closes the TOTP-replay gap).
            $table->unsignedBigInteger('totp_last_timestep')->nullable()->after('totp_confirmed_at');
        });

        Schema::create('two_factor_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_recovery_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_enabled', 'totp_confirmed_at', 'totp_last_timestep']);
        });
    }
};
