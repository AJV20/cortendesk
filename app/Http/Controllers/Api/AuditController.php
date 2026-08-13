<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use App\Services\AppriseNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * POST /api/audit/conn — spec §21. Three shapes from the controlled side:
     * 1. {"action":"new", ...}            connection opened (pre-auth)
     * 2. {"peer":[id,name], "type":N, …}  connection authorized (no action key)
     * 3. {"action":"close", ...}          connection closed
     */
    public function connection(Request $request): JsonResponse
    {
        $action = $request->input('action');
        $id = (string) $request->input('id', '');
        $connId = (int) $request->input('conn_id', 0);

        if ($id === '' || $connId === 0) {
            return response()->json((object) []);
        }

        if ($action === 'new') {
            AuditConnection::create([
                'action' => 'new',
                'conn_id' => $connId,
                'rustdesk_id' => $id,
                'ip' => (string) $request->input('ip', $request->ip()),
                'session_id' => (string) $request->input('session_id', ''),
                'uuid' => (string) $request->input('uuid', ''),
            ]);

            return response()->json((object) []);
        }

        $open = AuditConnection::where('rustdesk_id', $id)
            ->where('conn_id', $connId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if ($action === 'close') {
            $open?->update(['action' => 'close', 'closed_at' => now()]);

            return response()->json((object) []);
        }

        // Authorized: no action key; peer is a 2-element [id, name] array.
        $peer = (array) $request->input('peer', []);
        $attributes = [
            'action' => 'authorized',
            'from_peer' => (string) ($peer[0] ?? ''),
            'from_name' => (string) ($peer[1] ?? ''),
            'conn_type' => (int) $request->input('type', 0),
        ];

        if ($open !== null) {
            $open->update($attributes);
        } else {
            AuditConnection::create($attributes + [
                'conn_id' => $connId,
                'rustdesk_id' => $id,
                'session_id' => (string) $request->input('session_id', ''),
                'uuid' => (string) $request->input('uuid', ''),
                'ip' => $request->ip(),
            ]);
        }

        return response()->json((object) []);
    }

    /** POST /api/audit/file — spec §21. `info` is a JSON-encoded string. */
    public function file(Request $request): JsonResponse
    {
        $id = (string) $request->input('id', '');
        if ($id === '') {
            return response()->json((object) []);
        }

        $info = (string) $request->input('info', '');
        $decoded = json_decode($info, true) ?: [];

        AuditFileTransfer::create([
            'rustdesk_id' => $id,
            'from_peer' => (string) $request->input('peer_id', ''),
            'from_name' => (string) ($decoded['name'] ?? ''),
            'path' => (string) $request->input('path', ''),
            'info' => $info,
            'is_file' => (bool) $request->input('is_file', false),
            'direction' => (int) $request->input('type', 0),
            'file_count' => (int) ($decoded['num'] ?? 0),
            'ip' => (string) ($decoded['ip'] ?? $request->ip()),
            'uuid' => (string) $request->input('uuid', ''),
        ]);

        return response()->json((object) []);
    }

    /** POST /api/audit/alarm — spec §21. */
    public function alarm(Request $request): JsonResponse
    {
        $id = (string) $request->input('id', '');
        if ($id === '') {
            return response()->json((object) []);
        }

        $alarm = AlarmLog::create([
            'rustdesk_id' => $id,
            'uuid' => (string) $request->input('uuid', ''),
            'typ' => (int) $request->input('typ', 0),
            'info' => (string) $request->input('info', ''),
            'conn_id' => (int) $request->input('conn_id', 0) ?: null,
        ]);

        $notifications = app(AppriseNotifications::class);
        $device = Device::query()->where('rustdesk_id', $id)->first();
        $notifications->send(
            'security.alarm',
            $alarm->typeLabel(),
            'Device '.$id.' reported a security alarm.',
            'alarm:'.$alarm->id,
            $device,
        );

        if ($alarm->typ === 1) {
            $notifications->send(
                'remote_connection.failure',
                'Repeated remote connection failures',
                'Device '.$id.' reported more than 30 failed connection attempts.',
                'device:'.$id,
                $device,
            );
        }

        return response()->json((object) []);
    }

    /** PUT /api/audit — spec §21: end-of-connection note (Bearer). */
    public function note(Request $request): JsonResponse
    {
        // The audit-record guid reaches the controller via the rendezvous
        // protocol, which OSS hbbs doesn't forward — so the guid here can't
        // be matched to a row yet. The client only checks HTTP 200.
        return response()->json((object) []);
    }
}
