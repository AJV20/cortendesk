<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use SoftDeletes;

    /** Fallback seconds without a heartbeat before a device counts as offline. */
    public const ONLINE_WINDOW = 60;

    private static ?int $onlineWindowCache = null;

    /** Effective online window: settings table → config → constant. */
    public static function onlineWindow(): int
    {
        return self::$onlineWindowCache ??= (int) (
            Setting::get('online_window', (string) config('cortendesk.online_window', self::ONLINE_WINDOW)) ?: self::ONLINE_WINDOW
        );
    }

    protected $fillable = [
        'rustdesk_id',
        'uuid',
        'hostname',
        'os',
        'cpu',
        'memory',
        'username',
        'version',
        'alias',
        'note',
        'user_id',
        'device_group_id',
        'last_online_at',
        'last_online_ip',
    ];

    protected function casts(): array
    {
        return [
            'last_online_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    public function isOnline(): bool
    {
        return $this->last_online_at !== null
            && $this->last_online_at->gt(now()->subSeconds(self::ONLINE_WINDOW));
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_online_at', '>', now()->subSeconds(self::ONLINE_WINDOW));
    }

    public function scopeOffline(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('last_online_at')
                ->orWhere('last_online_at', '<=', now()->subSeconds(self::ONLINE_WINDOW));
        });
    }

    /**
     * Restrict to devices a console user may see. Admins: everything.
     * Non-admins: devices in a granted device group OR that they own.
     * This is the single source of truth for device visibility.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllDevices()) {
            return $query;
        }

        $groupIds = $user->accessibleDeviceGroupIds();

        return $query->where(function (Builder $q) use ($groupIds, $user) {
            $q->where('user_id', $user->id);
            if ($groupIds !== []) {
                $q->orWhereIn('device_group_id', $groupIds);
            }
        });
    }

    /** Platform slug used to pick an OS icon: windows, macos, linux, android, ios. */
    public function platform(): string
    {
        $os = strtolower($this->os ?? '');

        return match (true) {
            str_contains($os, 'windows') => 'windows',
            str_contains($os, 'mac') || str_contains($os, 'darwin') => 'macos',
            str_contains($os, 'android') => 'android',
            str_contains($os, 'ios') => 'ios',
            str_contains($os, 'linux') || str_contains($os, 'ubuntu')
                || str_contains($os, 'debian') || str_contains($os, 'fedora')
                || str_contains($os, 'arch') || str_contains($os, 'centos') => 'linux',
            default => 'unknown',
        };
    }
}
