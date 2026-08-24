<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_presence_notification_states', function (Blueprint $table) {
            $table->uuid('offline_claim_token')->nullable()->after('offline_notified_at');
            $table->timestamp('offline_claimed_at')->nullable()->after('offline_claim_token');
            $table->index('offline_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_presence_notification_states', function (Blueprint $table) {
            $table->dropIndex(['offline_claimed_at']);
            $table->dropColumn(['offline_claim_token', 'offline_claimed_at']);
        });
    }
};
