<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['guid', 'name', 'owner_user_id', 'is_personal', 'note'])]
class AddressBook extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $ab) {
            $ab->guid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AddressBookEntry::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AddressBookRule::class);
    }

    public static function personalFor(User $user): self
    {
        return static::firstOrCreate(
            ['owner_user_id' => $user->id, 'is_personal' => true],
            ['name' => 'My address book'],
        );
    }

    /**
     * Books a console user may see. Admins: all. Non-admins: books they own
     * plus shared books granted via a rule (everyone / this user / their group).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->is_admin) {
            return $query;
        }

        $groupIds = $user->groups()->pluck('user_groups.id')->all();

        return $query->where(function (Builder $q) use ($user, $groupIds) {
            $q->where('owner_user_id', $user->id)
                ->orWhere(fn (Builder $q) => $q
                    ->where('is_personal', false)
                    ->whereHas('rules', fn (Builder $r) => $r
                        ->where('subject_type', 'everyone')
                        ->orWhere(fn ($r) => $r->where('subject_type', 'user')->where('subject_id', $user->id))
                        ->orWhere(fn ($r) => $r->where('subject_type', 'group')->whereIn('subject_id', $groupIds))
                    )
                );
        });
    }
}
