<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Address books follow the RustDesk 1.2.6+ model: a user has a personal
     * address book plus optional shared ones; entries are peers with tags.
     */
    public function up(): void
    {
        Schema::create('address_books', function (Blueprint $table) {
            $table->id();
            $table->uuid('guid')->unique(); // exposed to clients in /api/ab/*
            $table->string('name');
            $table->foreignId('owner_user_id')->index();
            $table->boolean('is_personal')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        // Share rules: who can see/edit a shared address book
        Schema::create('address_book_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_book_id')->index();
            $table->string('subject_type', 16);           // user | group | everyone
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedTinyInteger('permission');    // 1=read 2=read/write 3=full control
            $table->timestamps();
        });

        Schema::create('address_book_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_book_id')->index();
            $table->string('rustdesk_id')->index();
            $table->string('alias')->nullable();
            $table->string('hostname')->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('username')->nullable();
            $table->text('password_enc')->nullable();      // encrypted stored peer password
            $table->string('login_name')->nullable();
            $table->boolean('force_always_relay')->default(false);
            $table->string('rdp_port', 8)->nullable();
            $table->string('rdp_username')->nullable();
            $table->json('tag_ids')->nullable();
            $table->timestamps();
            $table->unique(['address_book_id', 'rustdesk_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_book_id')->index();
            $table->string('name', 64);
            $table->unsignedInteger('color')->default(0); // RustDesk client encodes color as u32
            $table->timestamps();
            $table->unique(['address_book_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('address_book_entries');
        Schema::dropIfExists('address_book_rules');
        Schema::dropIfExists('address_books');
    }
};
