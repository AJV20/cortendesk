<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64)->index();
            // A device id, alarm id, or login source used for cooldown/audit.
            // Never a destination URL or other transport credential.
            $table->string('subject', 255)->nullable()->index();
            $table->string('status', 16)->index(); // sent | failed | suppressed
            $table->string('title', 255);
            $table->text('error')->nullable(); // sanitized: transport URLs/tokens are redacted
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
