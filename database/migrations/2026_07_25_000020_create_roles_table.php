<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            // Per-area permission matrix, same vocabulary as api_tokens.permissions:
            // {device,user,group,address_book,audit,strategy,setting,token} => none|r|rw
            $table->json('permissions');
            // Per-role 2FA enforcement (PLAN B6 deferred this to D4).
            $table->boolean('require_two_factor')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
