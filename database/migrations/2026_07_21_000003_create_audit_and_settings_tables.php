<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // POST /api/audit/conn
        Schema::create('audit_connections', function (Blueprint $table) {
            $table->id();
            $table->string('action', 32)->nullable();     // new / close
            $table->unsignedBigInteger('conn_id')->default(0)->index();
            $table->string('rustdesk_id')->index();       // controlled peer
            $table->string('from_peer')->nullable();      // controlling peer id
            $table->string('from_name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('session_id')->nullable();
            $table->unsignedTinyInteger('conn_type')->default(0); // remote/file/port-forward
            $table->string('uuid')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // POST /api/audit/file
        Schema::create('audit_file_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('rustdesk_id')->index();
            $table->string('from_peer')->nullable();
            $table->string('from_name')->nullable();
            $table->text('path')->nullable();
            $table->text('info')->nullable();
            $table->boolean('is_file')->default(true);
            $table->unsignedTinyInteger('direction')->default(0); // 0=send 1=receive
            $table->unsignedInteger('file_count')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('username');
            $table->string('client', 32)->default('web'); // web console | rustdesk client
            $table->string('device_id')->nullable();
            $table->string('device_os')->nullable();
            $table->string('ip', 45)->nullable();
            $table->boolean('successful')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('login_logs');
        Schema::dropIfExists('audit_file_transfers');
        Schema::dropIfExists('audit_connections');
    }
};
