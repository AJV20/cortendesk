<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_audits', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete: keep the audit trail even if the operator
            // account is later deleted (username is denormalized for that case).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username')->nullable();      // operator at time of action
            $table->string('action', 64);                // kebab, e.g. "device.delete"
            $table->string('target_type')->nullable();   // e.g. "device", "user"
            $table->string('target_id')->nullable();     // human ref (rustdesk_id, username…)
            $table->string('summary');                   // one human-readable line
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index('created_at');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_audits');
    }
};
