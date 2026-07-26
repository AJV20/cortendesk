<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['action', 'conn_id', 'rustdesk_id', 'from_peer', 'from_name', 'ip', 'session_id', 'conn_type', 'uuid', 'closed_at'])]
class AuditConnection extends Model
{
    /**
     * How long to wait before re-broadcasting a disconnect the client has not
     * acted on. A heartbeat can be lost; a live session must not be left
     * un-cancellable because of it. Long enough that a client acting normally
     * closes the session first.
     */
    public const DISCONNECT_RETRY_SECONDS = 60;

    /**
     * Connection ids this device should close, for the heartbeat response.
     *
     * Marks them sent in the same breath so a 15s heartbeat does not re-issue
     * the same instruction repeatedly while the client is acting on it.
     *
     * @return array<int, int>
     */
    public static function pendingDisconnectsFor(string $rustdeskId): array
    {
        $rows = static::query()
            ->where('rustdesk_id', $rustdeskId)
            ->whereNull('closed_at')
            ->whereNotNull('disconnect_requested_at')
            ->whereNotNull('conn_id')
            ->where(fn ($q) => $q->whereNull('disconnect_sent_at')
                ->orWhere('disconnect_sent_at', '<', now()->subSeconds(self::DISCONNECT_RETRY_SECONDS)))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        static::query()->whereIn('id', $rows->pluck('id'))
            ->update(['disconnect_sent_at' => now()]);

        return $rows->pluck('conn_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Is an operator waiting for this session to close? */
    public function isDisconnecting(): bool
    {
        return $this->closed_at === null && $this->disconnect_requested_at !== null;
    }

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'disconnect_requested_at' => 'datetime',
            'disconnect_sent_at' => 'datetime',
        ];
    }
}
