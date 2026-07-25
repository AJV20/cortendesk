<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strategies (PLAN C2): named client-option policies pushed to devices in
     * the heartbeat response (wire contract: docs/strategy-protocol.md).
     *
     * `options` is the strategy's config_options map, stored as JSON but ALWAYS
     * a flat map<string,string> on the wire — a single non-string value makes
     * the client drop the whole policy (protocol doc §1.3). App\Models\Strategy
     * sanitizes on the way in and on the way out.
     *
     * Three assignment levels, one row per target (the unique key on the target
     * column is what makes "exactly one strategy per device/user/group" a schema
     * guarantee rather than a convention). Resolution order is
     * device > user > device_group > default; see App\Models\Strategy::resolve().
     *
     * Pure table create — no existing table is touched, so this is safe to run
     * against live data.
     */
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('note', 500)->nullable();
            // Disabled strategies are skipped during resolution as if unassigned.
            $table->boolean('enabled')->default(true);
            // Fallback for devices with no assignment at any level. At most one
            // row may hold this; enforced by App\Models\Strategy, not the schema
            // (a partial unique index is not portable across MySQL/sqlite).
            $table->boolean('is_default')->default(false)->index();
            // Sticky mode: re-push on EVERY heartbeat instead of only on change,
            // so a local edit on the device is stomped within one interval.
            $table->boolean('enforce')->default(false);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('device_strategy', function (Blueprint $table) {
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->index()->constrained()->cascadeOnDelete();
        });

        Schema::create('strategy_user', function (Blueprint $table) {
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->index()->constrained()->cascadeOnDelete();
        });

        Schema::create('device_group_strategy', function (Blueprint $table) {
            $table->foreignId('device_group_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->index()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_group_strategy');
        Schema::dropIfExists('strategy_user');
        Schema::dropIfExists('device_strategy');
        Schema::dropIfExists('strategies');
    }
};
