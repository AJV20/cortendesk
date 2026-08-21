<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Set once at registration and never updated — last_online_ip is
            // the current address, this is where the device FIRST came from
            // (issue #46). Null on devices that registered before the column.
            $table->string('registered_ip', 45)->nullable()->after('last_online_ip');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('registered_ip');
        });
    }
};
