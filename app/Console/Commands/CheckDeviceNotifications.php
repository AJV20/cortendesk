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
                if (self::consumeRecoveryFor($device)) {
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

            // The legacy marker belongs to exactly one contender. A sweep
            // winner consumes it before materializing delivered state; an
            // online recovery winner consumes it for its sole recovery.
            $legacyClaim = DevicePresenceNotificationState::claimLegacyMarkerFor($device);
            if ($legacyClaim === null) {
                return;
            }

            if (is_string($legacyClaim)) {
                DevicePresenceNotificationState::convertClaimedLegacyMarkerFor($device, $legacyClaim);

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

    /** Consume a durable marker or the shared legacy claim exactly once. */
    private static function consumeRecoveryFor(Device $device): bool
    {
        $legacyClaim = DevicePresenceNotificationState::claimLegacyMarkerFor($device);

        // A claimant may be converting the marker into durable state. Let that
        // winner finish rather than deleting state it is about to materialize.
        if ($legacyClaim === null) {
            return false;
        }

        if ($legacyClaim === false) {
            return DevicePresenceNotificationState::consumeFor($device);
        }

        // Keep the legacy lock through both deletions. This also cleans up old
        // upgrades that had durable state plus the original cache marker.
        $durableRecovery = DevicePresenceNotificationState::consumeFor($device);
        $legacyRecovery = DevicePresenceNotificationState::consumeClaimedLegacyMarkerFor($device, $legacyClaim);

        return $durableRecovery || $legacyRecovery;
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
