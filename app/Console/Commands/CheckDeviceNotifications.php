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
use Illuminate\Support\Facades\Cache;

/** Detect device presence transitions for Apprise notifications. */
class CheckDeviceNotifications extends Command
{
    protected $signature = 'cortendesk:check-device-notifications';

    protected $description = 'Detect device offline/recovery transitions for Apprise notifications';

    public function handle(AppriseNotifications $notifications): int
    {
        DevicePresenceSnooze::pruneForSweep();

        if (! $notifications->isEnabledFor('device.offline') && ! $notifications->isEnabledFor('device.online')) {
            $this->info('Device presence notifications are disabled.');

            return self::SUCCESS;
        }

        // These inputs are deliberately loaded once. The sweep must not turn
        // grace/state/snooze checks into a query per device.
        $graceMinutes = $this->offlineGraceMinutes();
        $snoozedTargets = DevicePresenceSnooze::activeTargets();
        $states = DevicePresenceNotificationState::query()->get()->keyBy('device_id');
        $offline = 0;
        $recovered = 0;

        Device::query()->approved()->orderBy('id')->each(function (Device $device) use ($notifications, $graceMinutes, $snoozedTargets, $states, &$offline, &$recovered): void {
            $state = $states->get($device->id);
            $snoozed = isset($snoozedTargets[DevicePresenceSnooze::TARGET_DEVICE][$device->id])
                || ($device->device_group_id !== null && isset($snoozedTargets[DevicePresenceSnooze::TARGET_GROUP][$device->device_group_id]));

            if ($device->isOnline()) {
                if (($state?->offline_notified_at !== null || Cache::has(self::legacyMarkerKey($device)))
                    && self::consumeRecoveryFor($device)) {
                    // Recovery is at-most-once: consumeRecoveryFor removes the
                    // marker before transport, so a failed recovery is never
                    // retried and concurrent commands cannot duplicate it.
                    if (! $snoozed) {
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

            if ($state?->offline_notified_at !== null || $snoozed || ! $this->pastOfflineGrace($device, $graceMinutes)) {
                return;
            }

            if (Cache::has(self::legacyMarkerKey($device))) {
                // Keep the bounded 30-day legacy marker until online. The
                // durable marker prevents another offline send after upgrade.
                DevicePresenceNotificationState::recordLegacyOffline($device);

                return;
            }

            // Insert/reclaim a unique durable pending row before calling the
            // external service. Only its owner may complete or release it.
            $claim = DevicePresenceNotificationState::claimOfflineFor($device);
            if ($claim === null) {
                return;
            }

            $delivery = $notifications->send(
                'device.offline',
                'Device offline',
                self::deviceLabel($device).' stopped heartbeating.',
                'device:'.$device->rustdesk_id,
                $device,
            );

            if ($delivery?->status === NotificationDelivery::STATUS_SENT
                && DevicePresenceNotificationState::markDelivered($device, $claim)) {
                $offline++;
            } else {
                DevicePresenceNotificationState::releaseClaim($device, $claim);
            }
        });

        $this->info("Detected {$offline} offline and {$recovered} recovered device transition(s).");

        return self::SUCCESS;
    }

    /** Notify recovery on the first heartbeat after a confirmed offline delivery. */
    public static function reportOnline(Device $device, AppriseNotifications $notifications): void
    {
        if ($device->status !== Device::STATUS_ACTIVE || ! self::consumeRecoveryFor($device)) {
            return;
        }

        // A snooze closes the outage without emitting either side. Recovery is
        // at-most-once because the marker was atomically consumed above.
        if (! DevicePresenceSnooze::isActiveFor($device)) {
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

    /** Consume a durable marker or the bounded legacy cache fallback once. */
    private static function consumeRecoveryFor(Device $device): bool
    {
        if (DevicePresenceNotificationState::consumeFor($device)) {
            Cache::forget(self::legacyMarkerKey($device));

            return true;
        }

        // Cache::pull() alone is not atomic on every supported cache driver.
        // Claim the legacy marker first; a crashed consumer only delays retry by
        // this short timeout while the original 30-day marker remains bounded.
        $claimKey = self::legacyClaimKey($device);
        if (! Cache::add($claimKey, true, DevicePresenceNotificationState::CLAIM_TIMEOUT_SECONDS)) {
            return false;
        }

        if (Cache::pull(self::legacyMarkerKey($device)) !== null) {
            return true;
        }

        Cache::forget($claimKey);

        return false;
    }

    private static function legacyClaimKey(Device $device): string
    {
        return 'apprise:device-offline-claim:'.sha1($device->rustdesk_id);
    }

    private static function legacyMarkerKey(Device $device): string
    {
        return 'apprise:device-offline:'.sha1($device->rustdesk_id);
    }

    private function pastOfflineGrace(Device $device, int $graceMinutes): bool
    {
        $offlineSince = $device->last_online_at?->copy()->addSeconds(Device::onlineWindow())
            ?? $device->created_at;

        return $offlineSince instanceof Carbon
            && $offlineSince->addMinutes($graceMinutes)->lte(now());
    }

    private function offlineGraceMinutes(): int
    {
        return max(0, min(1440, (int) Setting::get('apprise_offline_grace_minutes', '0')));
    }
}
