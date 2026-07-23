<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal address book entries carry a client-side password *hash*
        // (spec §14/§16); shared entries carry an encrypted password instead.
        Schema::table('address_book_entries', function (Blueprint $table) {
            $table->string('hash')->nullable()->after('password_enc');
        });

        // POST /api/audit/alarm (spec §21)
        Schema::create('alarm_logs', function (Blueprint $table) {
            $table->id();
            $table->string('rustdesk_id')->index();
            $table->string('uuid')->nullable();
            $table->unsignedTinyInteger('typ')->default(0);
            $table->text('info')->nullable();
            $table->unsignedBigInteger('conn_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarm_logs');
        Schema::table('address_book_entries', function (Blueprint $table) {
            $table->dropColumn('hash');
        });
    }
};
