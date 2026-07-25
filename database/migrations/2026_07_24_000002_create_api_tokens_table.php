<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // sha256 of the plaintext bearer (shown once at creation, never stored raw).
            $table->string('token_hash', 64)->unique();
            // Short non-secret prefix kept for display in the token list ("cdk_ab12…").
            $table->string('token_prefix', 16);
            // Creator; nullable + nullOnDelete so revocation history survives account deletion.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Per-resource permission matrix: {device,user,group,strategy,address_book,audit} => none|r|rw
            $table->json('permissions');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
