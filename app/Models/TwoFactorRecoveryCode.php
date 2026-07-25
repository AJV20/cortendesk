<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One single-use 2FA recovery code (PLAN B6). The plaintext is only ever shown
 * to the user once at generation time; only the bcrypt hash is stored.
 */
#[Fillable(['user_id', 'code_hash', 'used_at'])]
#[Hidden(['code_hash'])]
class TwoFactorRecoveryCode extends Model
{
    public const UPDATED_AT = null; // created_at only

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
