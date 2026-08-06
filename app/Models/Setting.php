<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        // Device memoizes the online window per process; without this flush a
        // long-lived process (schedule:work, queue workers, the test runner)
        // would keep judging presence by the OLD value after a settings save.
        if ($key === 'online_window') {
            Device::flushOnlineWindowCache();
        }
    }

    /**
     * The configured relay pool as an ordered list of ['address' => …, 'geo' => …].
     *
     * Relay membership/selection is owned by the rendezvous server (hbbs), not the
     * console — see docs/relay-protocol.md. These rows document/manage the hbbs
     * `relay-servers` list; the console does not push them to clients. When no list
     * is configured we fall back to the single `relay_server` env/setting so existing
     * single-relay deployments keep working.
     *
     * @return array<int, array{address: string, geo: string}>
     */
    public static function relayServers(): array
    {
        $raw = static::get('relay_servers');

        if ($raw) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $rows = [];

                foreach ($decoded as $row) {
                    $address = trim((string) ($row['address'] ?? ''));

                    if ($address === '') {
                        continue;
                    }

                    $rows[] = [
                        'address' => $address,
                        'geo' => trim((string) ($row['geo'] ?? '')),
                    ];
                }

                if ($rows !== []) {
                    return $rows;
                }
            }
        }

        $single = trim((string) static::get('relay_server', config('cortendesk.relay_server')));

        return $single === '' ? [] : [['address' => $single, 'geo' => '']];
    }
}
