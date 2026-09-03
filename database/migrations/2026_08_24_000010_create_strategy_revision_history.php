<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        // Validate every legacy row before the first schema mutation. DDL is not
        // reliably transactional across supported engines, so a bad row must
        // leave this migration completely retryable.
        DB::table('strategies')->orderBy('id')->cursor()->each($decodeLegacyOptions);

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
        });

        // Existing strategies become revision 1 without changing their live
        // behavior. Keep the decoder here as defense in depth after preflight.
        DB::table('strategies')->orderBy('id')->each(function (object $strategy) use ($decodeLegacyOptions): void {
            $revisionId = DB::table('strategy_revisions')->insertGetId([
                'strategy_id' => $strategy->id,
                'revision' => 1,
                'snapshot' => json_encode([
                    'name' => $strategy->name,
                    'note' => $strategy->note,
                    'enabled' => (bool) $strategy->enabled,
                    'is_default' => (bool) $strategy->is_default,
                    'enforce' => (bool) $strategy->enforce,
                    'options' => $decodeLegacyOptions($strategy),
                ], JSON_THROW_ON_ERROR),
                'change_note' => 'Baseline created during strategy revision history upgrade',
                'created_by' => null,
                'created_by_name' => null,
                'affected_devices' => DB::table('devices')->where('strategy_id_resolved', $strategy->id)->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('strategies')->where('id', $strategy->id)->update(['active_revision_id' => $revisionId]);
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_revision_id');
        });

        Schema::dropIfExists('strategy_revisions');

        Schema::table('strategies', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
