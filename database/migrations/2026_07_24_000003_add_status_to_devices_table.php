<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Approval state for the deployment gate (PLAN B3). Default 'active'
            // preserves the pre-gate behavior: every existing/new device is
            // approved unless the gate quarantines a first-seen one as 'pending'.
            $table->string('status', 16)->default('active')->after('uuid');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
