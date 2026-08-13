<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null preserves the exact pre-#27 default: most recently seen first.
            $table->string('devices_sort', 32)->nullable()->after('devices_columns');
            $table->string('devices_sort_direction', 4)->nullable()->after('devices_sort');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['devices_sort', 'devices_sort_direction']);
        });
    }
};
