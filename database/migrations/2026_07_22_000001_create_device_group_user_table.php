<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grants: which device groups a (non-admin) user may see in the console.
     * Admins see everything and ignore this table.
     */
    public function up(): void
    {
        Schema::create('device_group_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'device_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_group_user');
    }
};
