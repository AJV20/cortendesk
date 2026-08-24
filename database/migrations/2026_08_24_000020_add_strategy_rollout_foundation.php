<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('confirmation_timeout_minutes')->default(15)->after('active_revision_id');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('strategy_rollout_ack_pending')->default(false)->after('strategy_acked_at');
        });

        Schema::create('strategy_rollouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->restrictOnDelete();
            $table->foreignId('strategy_revision_id')->constrained('strategy_revisions')->restrictOnDelete();
            $table->string('status', 16)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->unsignedInteger('batch_size')->default(25);
            $table->unsignedInteger('interval_minutes')->default(15);
            $table->timestamp('next_release_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('strategy_rollout_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_rollout_id')->constrained('strategy_rollouts')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_rustdesk_id')->nullable();
            $table->unsignedInteger('position');
            $table->timestamp('released_at')->nullable()->index();
            $table->unsignedBigInteger('delivered_version')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('timed_out_at')->nullable();
            $table->timestamps();
            $table->unique(['strategy_rollout_id', 'device_id']);
            $table->index(['strategy_rollout_id', 'position']);
            $table->index(['strategy_rollout_id', 'confirmed_at']);
            $table->index(['strategy_rollout_id', 'timed_out_at']);
            $table->index(['device_id', 'delivered_version', 'confirmed_at'], 'srd_device_version_confirmed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_rollout_devices');
        Schema::dropIfExists('strategy_rollouts');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('strategy_rollout_ack_pending');
        });

        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropColumn('confirmation_timeout_minutes');
        });
    }
};
