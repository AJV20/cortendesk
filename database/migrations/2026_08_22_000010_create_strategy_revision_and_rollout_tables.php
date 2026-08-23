<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('strategy_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->string('change_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->unsignedInteger('affected_devices')->default(0);
            $table->timestamps();
            $table->unique(['strategy_id', 'revision']);
        });

        Schema::table('strategies', function (Blueprint $table) {
            $table->foreignId('active_revision_id')
                ->nullable()
                ->after('options')
                ->constrained('strategy_revisions')
                ->nullOnDelete();
            $table->unsignedSmallInteger('confirmation_timeout_minutes')->default(15)->after('active_revision_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('strategy_sent_at')->nullable()->after('strategy_acked_at');
            $table->boolean('strategy_rollout_ack_pending')->default(false)->after('strategy_sent_at');
        });

        Schema::create('strategy_rollouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->restrictOnDelete();
            $table->foreignId('strategy_revision_id')->constrained('strategy_revisions')->cascadeOnDelete();
            $table->string('status', 16)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->unsignedInteger('batch_size')->default(25);
            $table->unsignedInteger('interval_minutes')->default(15);
            $table->timestamp('next_release_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('strategy_rollout_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_rollout_id')->constrained('strategy_rollouts')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_rustdesk_id')->nullable();
            $table->unsignedInteger('position');
            $table->timestamp('released_at')->nullable()->index();
            $table->unsignedBigInteger('delivered_version')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['strategy_rollout_id', 'device_id']);
            $table->index(['strategy_rollout_id', 'position']);
            $table->index(['device_id', 'delivered_version', 'confirmed_at'], 'srd_device_version_confirmed_idx');
        });

        $decodeLegacyOptions = static function (object $strategy): array {
            try {
                $rawOptions = $strategy->options === null ? '[]' : (string) $strategy->options;
                $options = json_decode($rawOptions, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    "Cannot create a baseline revision for strategy {$strategy->id}: options contains invalid JSON.",
                    previous: $exception,
                );
            }

            if (! is_array($options) || ($options !== [] && array_is_list($options))) {
                throw new RuntimeException(
                    "Cannot create a baseline revision for strategy {$strategy->id}: options must be a JSON object.",
                );
            }

            return $options;
        };

        // Validate every legacy row before writing any revision evidence. SQLite
        // accepts malformed text in JSON-declared columns, so fail visibly rather
        // than silently recording an empty policy snapshot.
        DB::table('strategies')->orderBy('id')->cursor()->each($decodeLegacyOptions);

        // Existing strategies become revision 1 without changing their live behavior.
        DB::table('strategies')->orderBy('id')->each(function (object $strategy) use ($decodeLegacyOptions): void {
            $snapshot = json_encode([
                'name' => $strategy->name,
                'note' => $strategy->note,
                'enabled' => (bool) $strategy->enabled,
                'is_default' => (bool) $strategy->is_default,
                'enforce' => (bool) $strategy->enforce,
                'confirmation_timeout_minutes' => (int) $strategy->confirmation_timeout_minutes,
                'options' => $decodeLegacyOptions($strategy),
            ], JSON_THROW_ON_ERROR);

            $revisionId = DB::table('strategy_revisions')->insertGetId([
                'strategy_id' => $strategy->id,
                'revision' => 1,
                'snapshot' => $snapshot,
                'change_note' => 'Baseline created during Strategies V2 upgrade',
                'created_by' => null,
                'affected_devices' => DB::table('devices')->where('strategy_id_resolved', $strategy->id)->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('strategies')->where('id', $strategy->id)->update(['active_revision_id' => $revisionId]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_rollout_devices');
        Schema::dropIfExists('strategy_rollouts');

        Schema::table('strategies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_revision_id');
            $table->dropColumn('confirmation_timeout_minutes');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['strategy_sent_at', 'strategy_rollout_ack_pending']);
        });

        Schema::dropIfExists('strategy_revisions');

        Schema::table('strategies', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
