<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_presence_notification_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            // Set only after Apprise records a successful offline delivery. This
            // durable marker survives cache eviction and process restarts.
            $table->timestamp('offline_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_presence_snoozes', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16); // device | group
            $table->unsignedBigInteger('target_id');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['target_type', 'target_id']);
            $table->index(['target_type', 'target_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_presence_snoozes');
        Schema::dropIfExists('device_presence_notification_states');
    }
};
