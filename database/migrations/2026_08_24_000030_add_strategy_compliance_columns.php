<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            // When the console last pushed a new policy map to the device. The
            // compliance view measures the confirmation deadline from here.
            $table->timestamp('strategy_sent_at')->nullable()->after('strategy_acked_at');
        });

        Schema::table('strategies', function (Blueprint $table): void {
            // Minutes a device may go unconfirmed after a push before the
            // compliance view stops calling it "pending".
            $table->unsignedSmallInteger('confirmation_timeout_minutes')->default(15)->after('enforce');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('strategy_sent_at');
        });

        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropColumn('confirmation_timeout_minutes');
        });
    }
};
