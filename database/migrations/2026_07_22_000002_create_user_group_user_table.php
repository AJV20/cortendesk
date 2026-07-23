<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users may now belong to MANY user groups. Creates the pivot, backfills
     * one membership per user from the old users.user_group_id column, then
     * drops that column. Safe on live data: the backfill runs before the drop.
     */
    public function up(): void
    {
        Schema::create('user_group_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'user_group_id']);
        });

        // Backfill: one pivot row per user that had a group. The join guards
        // against orphaned user_group_id values (the old column had no FK).
        DB::table('user_group_user')->insertUsing(
            ['user_id', 'user_group_id'],
            DB::table('users')
                ->join('user_groups', 'user_groups.id', '=', 'users.user_group_id')
                ->select('users.id', 'users.user_group_id'),
        );

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('user_group_id')->nullable()->after('avatar');
        });

        // Restore a single group per user; iterate descending so the lowest
        // group id wins when a user is in several groups.
        DB::table('user_group_user')->orderByDesc('user_group_id')->get()
            ->each(function ($row) {
                DB::table('users')->where('id', $row->user_id)
                    ->update(['user_group_id' => $row->user_group_id]);
            });

        Schema::dropIfExists('user_group_user');
    }
};
