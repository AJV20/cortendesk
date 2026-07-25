<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-device strategy state (PLAN C2/C3).
     *
     * `strategy_id_resolved` is the cached result of the device > user >
     * device_group > default resolution, recomputed whenever an assignment or a
     * strategy changes (App\Models\Strategy::recompute*). The heartbeat path
     * reads only this column — resolution is never computed per heartbeat.
     *
     * The remaining columns are the delivery engine's bookkeeping (C3):
     *  - strategy_version         the i64 `modified_at` token the device echoes;
     *                             bumped ONLY when the effective option map for
     *                             THIS device actually changes.
     *  - strategy_options         the option map that token stands for (what the
     *                             console wants the device to have).
     *  - strategy_acked_options   the map the device is believed to hold, i.e.
     *                             the one it last echoed our token for. Keys that
     *                             appear here but not in strategy_options are
     *                             pushed as "" (reset to built-in default) —
     *                             the client has no revert concept of its own
     *                             (docs/strategy-protocol.md §6.5).
     *  - strategy_acked_at        when that echo arrived; for the C4 inspector.
     *
     * All columns are nullable with no default change to existing rows, so a
     * device that has never been given a policy is indistinguishable from
     * before this migration.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('strategy_id_resolved')->nullable()->index()->after('device_group_id');
            $table->bigInteger('strategy_version')->nullable()->after('strategy_id_resolved');
            $table->json('strategy_options')->nullable()->after('strategy_version');
            $table->json('strategy_acked_options')->nullable()->after('strategy_options');
            $table->timestamp('strategy_acked_at')->nullable()->after('strategy_acked_options');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['strategy_id_resolved']);
            $table->dropColumn([
                'strategy_id_resolved',
                'strategy_version',
                'strategy_options',
                'strategy_acked_options',
                'strategy_acked_at',
            ]);
        });
    }
};
