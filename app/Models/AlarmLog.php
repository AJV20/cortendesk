<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rustdesk_id', 'uuid', 'typ', 'info', 'conn_id'])]
class AlarmLog extends Model
{
    /**
     * Alarm types posted by the client to POST /api/audit/alarm.
     * Enumerated in docs/client-api.md §21 (derived from the client's audit
     * code): label + bootstrap severity used for the console badge.
     *
     * @var array<int, array{label: string, severity: string}>
     */
    public const TYPES = [
        0 => ['label' => 'IP whitelist block', 'severity' => 'danger'],
        1 => ['label' => 'Many failed attempts (>30)', 'severity' => 'danger'],
        2 => ['label' => 'Rapid access attempts', 'severity' => 'warning'],
        6 => ['label' => 'IPv6 prefix attempts exceeded', 'severity' => 'warning'],
        7 => ['label' => 'Terminal login backoff', 'severity' => 'warning'],
        8 => ['label' => 'Terminal login concurrency', 'severity' => 'warning'],
        9 => ['label' => 'Session scope violation', 'severity' => 'danger'],
    ];

    protected function casts(): array
    {
        return [
            'typ' => 'integer',
        ];
    }

    /** Human label for this alarm's typ; "Type N" when unknown. */
    public function typeLabel(): string
    {
        return self::TYPES[$this->typ]['label'] ?? 'Type '.$this->typ;
    }

    /** Bootstrap severity suffix (danger|warning|info|secondary) for the badge. */
    public function typeSeverity(): string
    {
        return self::TYPES[$this->typ]['severity'] ?? 'secondary';
    }

    /**
     * The client sends `info` as a JSON-encoded string (spec §21). Decode it
     * into scalar key/value pairs for display; empty when not a JSON object.
     *
     * @return array<string, string>
     */
    public function infoPairs(): array
    {
        $decoded = json_decode((string) $this->info, true);
        if (! is_array($decoded)) {
            return [];
        }

        $pairs = [];
        foreach ($decoded as $key => $value) {
            $pairs[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $pairs;
    }
}
