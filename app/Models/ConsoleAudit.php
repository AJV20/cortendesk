<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Record of a mutating action performed in the console (who did what).
 * Written by explicit ConsoleAudit::record() calls from console components —
 * NOT model observers, which would also fire for the importer/seeder and
 * misattribute the actor.
 */
#[Fillable(['user_id', 'username', 'action', 'target_type', 'target_id', 'summary', 'ip'])]
class ConsoleAudit extends Model
{
    public const UPDATED_AT = null; // created_at only

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log a console action attributed to the current authenticated user.
     * No-op when unauthenticated (CLI/importer/seeder) so background writes
     * never produce anonymous audit noise.
     */
    public static function record(
        string $action,
        string $summary,
        ?string $targetType = null,
        ?string $targetId = null,
    ): void {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        static::create([
            'user_id' => $user->id,
            'username' => $user->username,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => $summary,
            'ip' => Request::ip(),
        ]);
    }
}
