<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SyncController extends Controller
{
    /**
     * POST /api/heartbeat — spec §8. Tokenless, fire-and-forget.
     *
     * Response keys (all optional): `sysinfo` (presence forces re-upload),
     * `disconnect` (conn ids to close), `modified_at` + `strategy` (Phase 3).
     */
    public function heartbeat(Request $request)
    {
        $id = (string) $request->input('id', '');
        $uuid = (string) $request->input('uuid', '');

        if ($id === '') {
            return response()->json((object) []);
        }

        $device = Device::withTrashed()->where('rustdesk_id', $id)->first();

        // Unknown device: ask it to introduce itself via /api/sysinfo.
        if ($device === null) {
            return response()->json(['sysinfo' => 1]);
        }

        // Recycled devices stay invisible: swallow the heartbeat without
        // requesting sysinfo, so the client idles quietly.
        if ($device->trashed()) {
            return response()->json((object) []);
        }

        // uuid pinning: once a device has a uuid, a mismatching sender may not
        // update presence (spoof guard for this tokenless endpoint).
        if (! $this->uuidMatches($device->uuid, $uuid)) {
            return response()->json((object) []);
        }

        $update = [
            'last_online_at' => now(),
            'last_online_ip' => $request->ip(),
        ];

        // Self-heal legacy imports: pin the uuid in the exact form the
        // client sends (base64) so future comparisons are direct.
        if ($uuid !== '' && $device->uuid !== $uuid) {
            $update['uuid'] = $uuid;
        }

        $device->forceFill($update)->saveQuietly();

        $response = [];

        // Device row exists but has never sent inventory — request it.
        if ($device->hostname === null || $device->hostname === '') {
            $response['sysinfo'] = 1;
        }

        return response()->json((object) $response);
    }

    /**
     * POST /api/sysinfo — spec §9. Tokenless. Plain-text response strings
     * are exact: SYSINFO_UPDATED | ID_NOT_FOUND.
     */
    public function sysinfo(Request $request): Response
    {
        $id = (string) $request->input('id', '');
        $uuid = (string) $request->input('uuid', '');

        if ($id === '') {
            return response('ID_NOT_FOUND');
        }

        $device = Device::withTrashed()->where('rustdesk_id', $id)->first();

        // Recycled: acknowledge so the client stops retrying, change nothing.
        if ($device?->trashed()) {
            return response('SYSINFO_UPDATED');
        }

        if ($device !== null && ! $this->uuidMatches($device->uuid, $uuid)) {
            // Same RustDesk id from a different machine — reject silently.
            return response('SYSINFO_UPDATED');
        }

        $attributes = [
            'uuid' => $uuid,
            'cpu' => (string) $request->input('cpu', ''),
            'memory' => (string) $request->input('memory', ''),
            'os' => (string) $request->input('os', ''),
            'hostname' => (string) $request->input('hostname', ''),
            'username' => (string) $request->input('username', ''),
            'version' => (string) $request->input('version', ''),
            'last_online_at' => now(),
            'last_online_ip' => $request->ip(),
        ];

        if ($device === null) {
            Device::create(['rustdesk_id' => $id] + $attributes);
        } else {
            $device->update($attributes);
        }

        return response('SYSINFO_UPDATED');
    }

    /**
     * Compare a stored device uuid with the one a client sent.
     *
     * Clients send base64(machine uuid) (spec §0.6), but databases imported
     * from lejianwen/rustdesk-api hold a mix of base64 and plain uuids (its
     * peers table stored both forms for the same machine). Treat values as
     * equal when they match after base64 canonicalization in either
     * direction; genuinely different machines still mismatch.
     */
    private function uuidMatches(string $stored, string $sent): bool
    {
        if ($stored === '' || $sent === '') {
            return true;
        }

        if (hash_equals($stored, $sent)) {
            return true;
        }

        $decodedSent = base64_decode($sent, true);
        if ($decodedSent !== false && hash_equals($stored, rtrim($decodedSent, "\0"))) {
            return true;
        }

        $decodedStored = base64_decode($stored, true);

        return $decodedStored !== false && hash_equals(rtrim($decodedStored, "\0"), $sent);
    }
}
