<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('setup_wizard_dismissed_at')->nullable()->after('devices_columns');
            $table->timestamp('setup_wizard_completed_at')->nullable()->after('setup_wizard_dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['setup_wizard_dismissed_at', 'setup_wizard_completed_at']);
        });
    }
};
