<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\AppriseNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks presence transitions for notifications. Device rows intentionally do
 * not carry notification state: cache keys expire after a bounded retention,
 * preserve existing schema semantics, and make disabling/re-enabling alerts
 * innocuous. The online endpoint also clears an offline marker immediately,
 * which means recovery is prompt rather than waiting for this minute sweep.
 */
class CheckDeviceNotifications extends Command
{
    public const OFFLINE_MARKER_PREFIX = 'apprise:device-offline:';

    private const MARKER_TTL_DAYS = 30;

    protected $signature = 'cortendesk:check-device-notifications';

    protected $description = 'Detect device offline/recovery transitions for Apprise notifications';

    public function handle(AppriseNotifications $notifications): int
    {
        if (! $notifications->isEnabledFor('device.offline') && ! $notifications->isEnabledFor('device.online')) {
            $this->info('Device presence notifications are disabled.');

            return self::SUCCESS;
        }

        $offline = 0;
        $recovered = 0;

        Device::query()->approved()->orderBy('id')->each(function (Device $device) use ($notifications, &$offline, &$recovered): void {
            $marker = self::marker($device->rustdesk_id);

            if (! $device->isOnline()) {
                if (Cache::add($marker, true, now()->addDays(self::MARKER_TTL_DAYS))) {
                    $notifications->send(
                        'device.offline',
                        'Device offline',
                        self::deviceLabel($device).' stopped heartbeating.',
                        'device:'.$device->rustdesk_id,
                        $device,
                    );
                    $offline++;
                }

                return;
            }

            if (Cache::pull($marker)) {
                $notifications->send(
                    'device.online',
                    'Device recovered',
                    self::deviceLabel($device).' is online again.',
                    'device:'.$device->rustdesk_id,
                    $device,
                );
                $recovered++;
            }
        });

        $this->info("Detected {$offline} offline and {$recovered} recovered device transition(s).");

        return self::SUCCESS;
    }

    /** Notify recovery on the first heartbeat after the scheduled sweep marked a device offline. */
    public static function reportOnline(Device $device, AppriseNotifications $notifications): void
    {
        if ($device->status !== Device::STATUS_ACTIVE || ! Cache::pull(self::marker($device->rustdesk_id))) {
            return;
        }

        // Called from the heartbeat and sysinfo endpoints, so this must not
        // hold the response open while an unreachable endpoint times out.
        $notifications->sendAfterResponse(
            'device.online',
            'Device recovered',
            self::deviceLabel($device).' is online again.',
            'device:'.$device->rustdesk_id,
            $device,
        );
    }

    public static function marker(string $rustdeskId): string
    {
        return self::OFFLINE_MARKER_PREFIX.sha1($rustdeskId);
    }

    public static function deviceLabel(Device $device): string
    {
        $name = trim((string) ($device->alias ?: $device->hostname));

        return $name === '' ? 'Device '.$device->rustdesk_id : $name.' ('.$device->rustdesk_id.')';
    }
}
