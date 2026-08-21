<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DevicePresenceNotificationState;
use App\Models\DevicePresenceSnooze;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Services\AppriseNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Detect device presence transitions for Apprise notifications. */
class CheckDeviceNotifications extends Command
{
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
            if ($device->isOnline()) {
                // Claim before sending: this one-statement conditional delete
                // lets only the scheduler or a concurrent heartbeat own the
                // paired recovery. A snooze still consumes the marker silently.
                if (DevicePresenceNotificationState::consumeFor($device)) {
                    if (! DevicePresenceSnooze::isActiveFor($device)) {
                        $notifications->send(
                            'device.online',
                            'Device recovered',
                            self::deviceLabel($device).' is online again.',
                            'device:'.$device->rustdesk_id,
                            $device,
                        );
                        $recovered++;
                    }
                }

                return;
            }

            $state = DevicePresenceNotificationState::query()->firstOrNew(['device_id' => $device->id]);

            if ($state->exists || DevicePresenceSnooze::isActiveFor($device) || ! $this->pastOfflineGrace($device)) {
                return;
            }

            $delivery = $notifications->send(
                'device.offline',
                'Device offline',
                self::deviceLabel($device).' stopped heartbeating.',
                'device:'.$device->rustdesk_id,
                $device,
            );

            // Only a confirmed send creates the durable outage marker. A failed,
            // disabled, scoped-out, or cooldown-suppressed send cannot produce a
            // recovery alert later, and can be retried by a future sweep.
            if ($delivery?->status === NotificationDelivery::STATUS_SENT) {
                DevicePresenceNotificationState::query()->create([
                    'device_id' => $device->id,
                    'offline_notified_at' => now(),
                ]);
                $offline++;
            }
        });

        $this->info("Detected {$offline} offline and {$recovered} recovered device transition(s).");

        return self::SUCCESS;
    }

    /** Notify recovery on the first heartbeat after a confirmed offline delivery. */
    public static function reportOnline(Device $device, AppriseNotifications $notifications): void
    {
        if ($device->status !== Device::STATUS_ACTIVE) {
            return;
        }

        // Atomically claim the marker before queuing transport work. A scheduler
        // sweep racing this heartbeat receives false and emits nothing.
        if (! DevicePresenceNotificationState::consumeFor($device)) {
            return;
        }

        // See handle(): a snooze closes the outage without emitting either side.
        if (! DevicePresenceSnooze::isActiveFor($device)) {
            // Called from heartbeat/sysinfo endpoints, so keep transport work
            // after the HTTP response has been flushed.
            $notifications->sendAfterResponse(
                'device.online',
                'Device recovered',
                self::deviceLabel($device).' is online again.',
                'device:'.$device->rustdesk_id,
                $device,
            );
        }
    }

    public static function deviceLabel(Device $device): string
    {
        $name = trim((string) ($device->alias ?: $device->hostname));

        return $name === '' ? 'Device '.$device->rustdesk_id : $name.' ('.$device->rustdesk_id.')';
    }

    private function pastOfflineGrace(Device $device): bool
    {
        $offlineSince = $device->last_online_at?->copy()->addSeconds(Device::onlineWindow())
            ?? $device->created_at;

        return $offlineSince instanceof Carbon
            && $offlineSince->addMinutes($this->offlineGraceMinutes())->lte(now());
    }

    private function offlineGraceMinutes(): int
    {
        return max(0, min(1440, (int) Setting::get('apprise_offline_grace_minutes', '0')));
    }
}
