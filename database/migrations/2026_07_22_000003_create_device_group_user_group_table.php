<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grants: which device groups (folders) a USER GROUP may see. A user's
     * effective folder access is the union of their per-user grants
     * (device_group_user) and the grants of every user group they belong to.
     * Pure table create — safe on live data.
     */
    public function up(): void
    {
        Schema::create('device_group_user_group', function (Blueprint $table) {
            $table->foreignId('device_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['device_group_id', 'user_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_group_user_group');
    }
};
