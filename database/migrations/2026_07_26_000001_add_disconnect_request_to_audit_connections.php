<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-requested session termination.
 *
 * The heartbeat response carries a `disconnect` array of connection ids and the
 * client closes each one ("Closed manually by web console") — see
 * docs/client-api.md §8. The request is recorded on the session row itself
 * rather than in a separate queue, because the thing being cancelled IS a row
 * here and the session closing is what ends the request: the client reports the
 * close on the audit endpoint, which sets closed_at, and the row stops being
 * eligible.
 *
 * `sent_at` exists so a request is not re-broadcast on every 15s heartbeat
 * while the client acts on it, and so it CAN be retried if the heartbeat that
 * carried it was lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_connections', function (Blueprint $table) {
            $table->timestamp('disconnect_requested_at')->nullable()->after('closed_at');
            $table->timestamp('disconnect_sent_at')->nullable()->after('disconnect_requested_at');
            $table->foreignId('disconnect_requested_by')->nullable()->after('disconnect_sent_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disconnect_requested_by');
            $table->dropColumn(['disconnect_requested_at', 'disconnect_sent_at']);
        });
    }
};
