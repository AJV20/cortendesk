<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user column selection for the Devices screen (issue #16). Nullable on
// purpose: null means "the defaults", so existing users see exactly the table
// they had before. (Nullable text also sidesteps the MariaDB NOT-NULL
// timestamp trap — see RELEASE.md 1b — though it applies only to timestamps.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('devices_columns')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('devices_columns');
        });
    }
};
