<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group↔group visibility (PLAN B4). A polymorphic "accessed from" grant:
     * an ACCESSOR (a user group, or an individual user) may see into a TARGET
     * (another user group's members, or a device group's folder).
     *
     *   accessor = user_group | user
     *   target   = user_group | device_group
     *
     * This layers ON TOP of the existing device_group_user / device_group_user_group
     * grants (which stay authoritative for the user-group editor's folder picker);
     * both are unioned by User::accessibleDeviceGroupIds(). User-group targets feed
     * the /api/users group-mate logic via User::visibleUserIds(). Pure table create —
     * safe on live data.
     */
    public function up(): void
    {
        Schema::create('group_accesses', function (Blueprint $table) {
            $table->id();
            $table->string('accessor_type', 16); // user_group | user
            $table->unsignedBigInteger('accessor_id');
            $table->string('target_type', 16);   // user_group | device_group
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(
                ['accessor_type', 'accessor_id', 'target_type', 'target_id'],
                'group_accesses_unique',
            );
            $table->index(['target_type', 'target_id']);
            $table->index(['accessor_type', 'accessor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_accesses');
    }
};
