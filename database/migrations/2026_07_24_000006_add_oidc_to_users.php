<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OIDC single sign-on (PLAN D3).
 *
 * Identity is keyed on the issuer + subject pair, not the email address: `sub`
 * is the only claim an IdP guarantees to be stable and unique, while emails get
 * reassigned between people. An email is used only for FIRST-TIME linking of an
 * existing local account, and only when the IdP asserts `email_verified` — see
 * OidcService::resolveUser().
 *
 * `oidc_status` gates just-in-time provisioned accounts when the operator picks
 * the "pending approval" new-user policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // local = password login; oidc = provisioned by / linked to the IdP.
            $table->string('auth_provider', 16)->default('local')->after('password');
            $table->string('oidc_sub', 255)->nullable()->after('auth_provider');
            $table->string('oidc_iss', 255)->nullable()->after('oidc_sub');
            // active = may sign in; pending = awaiting administrator approval.
            $table->string('oidc_status', 16)->nullable()->after('oidc_iss');

            // The identity lookup key. Unique so two accounts can never claim the
            // same IdP identity; nullable columns are exempt from the constraint
            // in both MySQL and SQLite, so local accounts are unaffected.
            $table->unique(['oidc_iss', 'oidc_sub'], 'users_oidc_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_oidc_identity_unique');
            $table->dropColumn(['auth_provider', 'oidc_sub', 'oidc_iss', 'oidc_status']);
        });
    }
};
