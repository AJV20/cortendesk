<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

// AddressBookRule constants are referenced in permissionFor().

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
     * Effective permission tier of $user on this book (PLAN B4). This is the
     * single source of truth shared by the console (AddressBookManager) and the
     * client AB API so ro / rw / full is enforced consistently.
     *
     *   0 = no access
     *   PERM_READ (1)       = view only
     *   PERM_READ_WRITE (2) = manage entries (peers)
     *   PERM_FULL (3)       = manage entries + tags + rules
     *
     * Owners (and admins) always have full control; personal books are private
     * to their owner.
     */
    public function permissionFor(User $user): int
    {
        if ($this->is_personal) {
            return $this->owner_user_id === $user->id ? AddressBookRule::PERM_FULL : 0;
        }

        if ($this->owner_user_id === $user->id || $user->is_admin) {
            return AddressBookRule::PERM_FULL;
        }

        $groupIds = $user->groups()->pluck('user_groups.id')->all();

        return (int) $this->rules
            ->filter(function (AddressBookRule $rule) use ($user, $groupIds) {
                return match ($rule->subject_type) {
                    'everyone' => true,
                    'user' => (int) $rule->subject_id === $user->id,
                    'group' => in_array((int) $rule->subject_id, array_map('intval', $groupIds), true),
                    default => false,
                };
            })
            ->max('permission');
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
