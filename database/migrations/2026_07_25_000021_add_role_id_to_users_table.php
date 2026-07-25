<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = no delegated role, i.e. the behaviour every non-admin has
            // had until now (App\Support\Permissions::LEGACY_USER). Deleting a
            // role nulls this column, reverting its holders to that baseline.
            $table->foreignId('role_id')->nullable()->after('is_admin')
                ->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
