<?php

namespace App\Services;

use App\Models\Device;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Small synchronous Apprise API client. It uses Laravel's HTTP client rather
 * than spawning a binary, keeping URLs and credentials out of process args.
 */
class AppriseNotifications
{
    public const MODE_CONFIG = 'config';

    public const MODE_URLS = 'urls';

    public const EVENTS = [
        'device.pending_approval' => 'Device pending approval',
        'device.offline' => 'Device offline',
        'device.online' => 'Device recovered',
        'console.login_failed' => 'Failed console login',
        'security.alarm' => 'Security alarm',
        'remote_connection.failure' => 'Repeated remote connection failure',
    ];

    /**
     * Send an enabled event. The subject scopes its cooldown (a silent device
     * must not suppress a different device's security event).
     */
    public function send(string $event, string $title, string $body, ?string $subject = null, ?Device $device = null): ?NotificationDelivery
    {
        if (! $this->isEnabledFor($event) || ! $this->allowsDevice($event, $device)) {
            return null;
        }

        $cooldown = $this->cooldownSeconds();
        $key = 'apprise:cooldown:'.sha1($event.'|'.($subject ?? 'global'));

        if ($cooldown > 0 && ! Cache::add($key, true, $cooldown)) {
            // A minute-by-minute presence sweep would otherwise create a log
            // row for every suppressed tick. Delivery history records actual
            // attempts; the cache enforces quiet periods without audit noise.
            return null;
        }

        return $this->deliver($event, $title, $body, $subject);
    }

    /** Send a saved-config test regardless of the event switches. */
    public function test(): NotificationDelivery
    {
        return $this->deliver(
            'test',
            'CortenDesk notification test',
            'This is a test notification from '.config('app.name').'.',
            'settings-test',
        );
    }

    public function isConfigured(): bool
    {
        $endpoint = $this->secret('apprise_endpoint');
        if (! $this->validEndpoint($endpoint)) {
            return false;
        }

        return $this->mode() === self::MODE_CONFIG
            ? trim($this->secret('apprise_config_key')) !== ''
            : $this->urls() !== [];
    }

    public function isEnabledFor(string $event): bool
    {
        return Setting::get('apprise_enabled', '0') === '1'
            && array_key_exists($event, self::EVENTS)
            && Setting::get('apprise_event_'.$this->settingSuffix($event), '0') === '1'
            && $this->isConfigured();
    }

    /**
     * Redact transport credentials from delivery failures and UI/audit strings.
     * The method is public so callers adding user-visible diagnostics can use
     * the same single safe transformation.
     */
    public static function redact(string $value): string
    {
        // Keep transport details out of persistent logs. Apprise destinations use
        // many non-HTTP schemes and commonly place tokens in their path, so the
        // whole URI is removed rather than trying to preserve a "safe" prefix.
        $value = preg_replace(
            '~\b[a-z][a-z0-9+.-]*://[^\s"\']+~i',
            '[redacted URL]',
            $value,
        ) ?? '[redacted error]';

        return preg_replace(
            '/\b(token|secret|password|authorization|api[_-]?key|access[_-]?token)\s*[=:]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $value,
        ) ?? '[redacted error]';
    }

    private function deliver(string $event, string $title, string $body, ?string $subject): NotificationDelivery
    {
        if (! $this->isConfigured()) {
            return $this->record($event, $title, $subject, NotificationDelivery::STATUS_FAILED, 'Apprise is not configured.');
        }

        // Best-effort delivery with tight transport deadlines. Notification
        // failures are contained and never change API or authentication
        // responses, but enabled events can add up to three seconds while an
        // unreachable self-hosted Apprise endpoint times out.
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(3)
                ->connectTimeout(1)
                ->post($this->notifyUrl(), $this->payload($title, $body));

            if ($response->successful()) {
                return $this->record($event, $title, $subject, NotificationDelivery::STATUS_SENT);
            }

            return $this->record(
                $event,
                $title,
                $subject,
                NotificationDelivery::STATUS_FAILED,
                'Apprise returned HTTP '.$response->status().'. '.self::redact((string) $response->body()),
            );
        } catch (ConnectionException $e) {
            Log::warning('Apprise notification delivery failed.', ['event' => $event, 'error' => self::redact($e->getMessage())]);

            return $this->record($event, $title, $subject, NotificationDelivery::STATUS_FAILED, self::redact($e->getMessage()));
        } catch (Throwable $e) {
            // Notifications must never break auth, presence, or audit APIs.
            Log::warning('Apprise notification delivery failed.', ['event' => $event, 'error' => self::redact($e->getMessage())]);

            return $this->record($event, $title, $subject, NotificationDelivery::STATUS_FAILED, self::redact($e->getMessage()));
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $title, string $body): array
    {
        $payload = [
            'title' => $title,
            'body' => $body,
            'type' => 'warning',
        ];

        if ($this->mode() === self::MODE_URLS) {
            $payload['urls'] = $this->urls();
        }

        return $payload;
    }

    private function notifyUrl(): string
    {
        $endpoint = $this->secret('apprise_endpoint');
        $parts = parse_url($endpoint);

        // isConfigured() verified this already. Preserve an endpoint query
        // (some self-hosted Apprise APIs protect their API route that way) but
        // put it AFTER the /notify path, never inside the config key path.
        $base = ($parts['scheme'] ?? 'https').'://';
        if (isset($parts['user']) || isset($parts['pass'])) {
            $base .= rawurlencode((string) ($parts['user'] ?? ''));
            if (isset($parts['pass'])) {
                $base .= ':'.rawurlencode((string) $parts['pass']);
            }
            $base .= '@';
        }
        $base .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/').'/notify';
        if ($this->mode() === self::MODE_CONFIG) {
            $path .= '/'.rawurlencode($this->secret('apprise_config_key'));
        }

        return $base.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function mode(): string
    {
        return Setting::get('apprise_delivery_mode', self::MODE_CONFIG) === self::MODE_URLS
            ? self::MODE_URLS
            : self::MODE_CONFIG;
    }

    /** @return list<string> */
    private function urls(): array
    {
        $decoded = json_decode($this->secret('apprise_urls'), true);

        return is_array($decoded)
            ? array_values(array_filter(array_map('trim', $decoded), fn ($url) => is_string($url) && $url !== ''))
            : [];
    }

    private function secret(string $key): string
    {
        $value = Setting::get($key, '') ?? '';
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Existing plaintext values are ignored rather than accidentally
            // copied into a URL/error path. Operators can re-save securely.
            return '';
        }
    }

    private function cooldownSeconds(): int
    {
        return max(0, min(1440, (int) Setting::get('apprise_cooldown_minutes', '15'))) * 60;
    }

    private function settingSuffix(string $event): string
    {
        return str_replace(['.', '-'], '_', $event);
    }

    private function allowsDevice(string $event, ?Device $device): bool
    {
        $suffix = $this->settingSuffix($event);
        if ($device === null || Setting::get('apprise_scope_'.$suffix, 'all') !== 'selected') {
            return true;
        }

        $groupIds = json_decode((string) Setting::get('apprise_scope_groups_'.$suffix, '[]'), true);
        $deviceIds = json_decode((string) Setting::get('apprise_scope_devices_'.$suffix, '[]'), true);
        $groups = array_map('intval', is_array($groupIds) ? $groupIds : []);
        $devices = array_map('intval', is_array($deviceIds) ? $deviceIds : []);

        return in_array((int) $device->id, $devices, true)
            || ($device->device_group_id !== null && in_array((int) $device->device_group_id, $groups, true));
    }

    private function validEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && isset($parts['host'])
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
    }

    private function record(string $event, string $title, ?string $subject, string $status, ?string $error = null): NotificationDelivery
    {
        return NotificationDelivery::create([
            'event' => $event,
            'subject' => $subject,
            'status' => $status,
            'title' => self::redact($title),
            'error' => $error === null ? null : mb_substr(self::redact($error), 0, 2_000),
        ]);
    }
}
