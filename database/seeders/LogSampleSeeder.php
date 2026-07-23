<?php

namespace Database\Seeders;

use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\LoginLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LogSampleSeeder extends Seeder
{
    /** @var list<string> */
    protected array $deviceIds = [
        '123456789', '147258369', '321654987', '456123789',
        '789321456', '852741963', '963852741', '987654321',
    ];

    /** @var array<string, string> Controlling peers => operator names */
    protected array $fromPeers = [
        '111222333' => 'ops-laptop',
        '444555666' => 'helpdesk-01',
        '777888999' => 'admin-workstation',
        '222333444' => 'noc-desk',
    ];

    /** @var list<string> */
    protected array $ips = [
        '203.0.113.10', '203.0.113.24', '198.51.100.7', '198.51.100.42',
        '192.0.2.15', '192.0.2.88', '203.0.113.199', '198.51.100.130',
    ];

    public function run(): void
    {
        $this->seedConnections();
        $this->seedFileTransfers();
        $this->seedLoginLogs();
    }

    protected function seedConnections(): void
    {
        $rows = [];

        for ($i = 0; $i < 40; $i++) {
            $started = Carbon::now()
                ->subMinutes(random_int(0, 14 * 24 * 60))
                ->subSeconds(random_int(0, 59));

            // ~1 in 6 sessions still open, but only if recent (< 4h old).
            $isOpen = random_int(1, 6) === 1 && $started->gt(Carbon::now()->subHours(4));
            $closed = $isOpen ? null : $started->copy()->addSeconds(random_int(45, 5400));

            $fromPeer = array_rand($this->fromPeers);
            $connType = [0, 0, 0, 0, 1, 1, 2][random_int(0, 6)]; // mostly remote control

            $rows[] = [
                'action' => $isOpen ? 'new' : 'close',
                'conn_id' => 100000 + $i,
                'rustdesk_id' => $this->deviceIds[array_rand($this->deviceIds)],
                'from_peer' => $fromPeer,
                'from_name' => $this->fromPeers[$fromPeer],
                'ip' => $this->ips[array_rand($this->ips)],
                'session_id' => (string) random_int(1000000000, 9999999999),
                'conn_type' => $connType,
                'uuid' => (string) Str::uuid(),
                'closed_at' => $closed,
                'created_at' => $started,
                'updated_at' => $closed ?? $started,
            ];
        }

        AuditConnection::insert($rows);
    }

    protected function seedFileTransfers(): void
    {
        $paths = [
            ['/Users/admin/Documents/report-q2.pdf', true, 1],
            ['C:\\Users\\jsmith\\Desktop\\invoices', false, 12],
            ['/home/deploy/backups/db-dump.sql.gz', true, 1],
            ['C:\\Temp\\drivers\\printer-setup.exe', true, 1],
            ['/Users/admin/Pictures/screenshots', false, 34],
            ['C:\\Users\\mlee\\Downloads\\update.zip', true, 1],
            ['/var/log/app/errors.log', true, 1],
            ['D:\\Shared\\contracts\\2026', false, 8],
            ['/Users/admin/Desktop/config.yaml', true, 1],
            ['C:\\Users\\jsmith\\Documents\\budget.xlsx', true, 1],
        ];

        $rows = [];

        for ($i = 0; $i < 15; $i++) {
            $when = Carbon::now()
                ->subMinutes(random_int(0, 14 * 24 * 60))
                ->subSeconds(random_int(0, 59));

            [$path, $isFile, $count] = $paths[$i % count($paths)];
            $fromPeer = array_rand($this->fromPeers);

            $rows[] = [
                'rustdesk_id' => $this->deviceIds[array_rand($this->deviceIds)],
                'from_peer' => $fromPeer,
                'from_name' => $this->fromPeers[$fromPeer],
                'path' => $path,
                'info' => json_encode(['files' => $count]),
                'is_file' => $isFile,
                'direction' => random_int(0, 1),
                'file_count' => $count,
                'ip' => $this->ips[array_rand($this->ips)],
                'uuid' => (string) Str::uuid(),
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        AuditFileTransfer::insert($rows);
    }

    protected function seedLoginLogs(): void
    {
        $users = ['admin', 'jsmith', 'mlee', 'support'];
        $clients = ['web', 'web', 'desktop', 'desktop', 'mobile']; // weighted
        $oses = ['Windows 11', 'macOS 15.5', 'Ubuntu 24.04', 'Android 15', 'iOS 18'];
        $failNotes = ['wrong password', 'account disabled', 'wrong password'];

        $rows = [];

        for ($i = 0; $i < 25; $i++) {
            $when = Carbon::now()
                ->subMinutes(random_int(0, 14 * 24 * 60))
                ->subSeconds(random_int(0, 59));

            $successful = $i % 6 !== 5; // ~4 failures out of 25
            $client = $clients[array_rand($clients)];

            $rows[] = [
                'user_id' => $successful ? 1 : null,
                'username' => $users[array_rand($users)],
                'client' => $client,
                'device_id' => $client === 'web' ? null : $this->deviceIds[array_rand($this->deviceIds)],
                'device_os' => $client === 'web' ? null : $oses[array_rand($oses)],
                'ip' => $this->ips[array_rand($this->ips)],
                'successful' => $successful,
                'note' => $successful ? null : $failNotes[array_rand($failNotes)],
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        LoginLog::insert($rows);
    }
}
