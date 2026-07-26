<?php

namespace Database\Seeders;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\LoginLog;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for documentation screenshots and manual exploration.
 *
 * NOT for production, and deliberately never wired into DatabaseSeeder — run it
 * explicitly. Everything here is fictional: example.com addresses, RFC 5737
 * documentation IP ranges (192.0.2.0/24, 198.51.100.0/24) and invented hostnames.
 * No real infrastructure, customer or personal data appears in a screenshot
 * taken from this data set.
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding demo data…');

        // --- Roles -----------------------------------------------------------
        $helpdesk = Role::firstOrCreate(['name' => 'Helpdesk'], [
            'permissions' => Role::normalizePermissions([
                'device' => 'rw', 'user' => 'none', 'group' => 'r',
                'address_book' => 'rw', 'audit' => 'r', 'strategy' => 'none',
                'setting' => 'none', 'token' => 'none',
            ]),
        ]);

        Role::firstOrCreate(['name' => 'Auditor'], [
            'permissions' => Role::normalizePermissions([
                'device' => 'r', 'user' => 'r', 'group' => 'r',
                'address_book' => 'r', 'audit' => 'rw', 'strategy' => 'r',
                'setting' => 'none', 'token' => 'none',
            ]),
        ]);

        // --- Device groups ("folders") ---------------------------------------
        $groups = [];
        foreach (['Head Office', 'Warehouse', 'Retail — North', 'Retail — South', 'Field Laptops'] as $name) {
            $groups[$name] = DeviceGroup::firstOrCreate(['name' => $name]);
        }

        // --- User groups + users ---------------------------------------------
        $support = UserGroup::firstOrCreate(['name' => 'Support Team']);
        $field = UserGroup::firstOrCreate(['name' => 'Field Engineers']);

        $people = [
            ['dana',    'Dana Whitfield',  'dana@example.com',    $helpdesk->id, $support],
            ['ravi',    'Ravi Menon',      'ravi@example.com',    $helpdesk->id, $support],
            ['imogen',  'Imogen Clarke',   'imogen@example.com',  null,          $field],
            ['tomas',   'Tomas Berg',      'tomas@example.com',   null,          $field],
        ];

        $users = [];
        foreach ($people as [$username, $name, $email, $roleId, $userGroup]) {
            $u = User::firstOrCreate(['username' => $username], [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('demo-password-not-real'),
                'is_admin' => false,
                'is_active' => true,
                'role_id' => $roleId,
            ]);
            $userGroup->users()->syncWithoutDetaching([$u->id]);
            $users[$username] = $u;
        }

        // Support team reaches the office and retail estates; field engineers
        // get the laptops. This is what makes the access-scoping page real.
        $support->deviceGroups()->syncWithoutDetaching([
            $groups['Head Office']->id, $groups['Retail — North']->id, $groups['Retail — South']->id,
        ]);
        $field->deviceGroups()->syncWithoutDetaching([$groups['Field Laptops']->id]);

        // --- Devices ----------------------------------------------------------
        $fleet = [
            // [id, hostname, os, version, group, owner, minutes since heartbeat]
            ['118 452 907', 'HO-RECEPTION',   'Windows 11 Pro',      '1.4.2', 'Head Office',    'dana',   0],
            ['204 771 336', 'HO-FINANCE-01',  'Windows 11 Pro',      '1.4.2', 'Head Office',    'dana',   0],
            ['337 019 552', 'HO-DESIGN-MAC',  'macOS 15.2',          '1.4.1', 'Head Office',    'imogen', 1],
            ['451 662 108', 'WH-SCANNER-01',  'Windows 10 IoT',      '1.3.8', 'Warehouse',      null,     0],
            ['509 238 774', 'WH-SCANNER-02',  'Windows 10 IoT',      '1.3.8', 'Warehouse',      null,   240],
            ['613 445 290', 'WH-OFFICE',      'Ubuntu 24.04',        '1.4.2', 'Warehouse',      'ravi',   2],
            ['722 806 131', 'RN-TILL-01',     'Windows 11 Pro',      '1.4.2', 'Retail — North', 'ravi',   0],
            ['788 190 664', 'RN-TILL-02',     'Windows 11 Pro',      '1.4.0', 'Retail — North', 'ravi', 1440],
            ['845 337 209', 'RN-BACKOFFICE',  'Windows 11 Pro',      '1.4.2', 'Retail — North', 'ravi',   1],
            ['901 552 418', 'RS-TILL-01',     'Windows 11 Pro',      '1.4.2', 'Retail — South', 'dana',   0],
            ['934 771 085', 'RS-TILL-02',     'Windows 11 Pro',      '1.4.2', 'Retail — South', 'dana',   3],
            ['967 118 340', 'RS-STOCKROOM',   'Debian 12',           '1.4.1', 'Retail — South', null,    45],
            ['112 903 776', 'FL-IMOGEN-X1',   'Windows 11 Pro',      '1.4.2', 'Field Laptops',  'imogen', 0],
            ['155 640 228', 'FL-TOMAS-MBP',   'macOS 15.2',          '1.4.2', 'Field Laptops',  'tomas',  1],
            ['178 224 951', 'FL-SPARE-01',    'Windows 11 Pro',      '1.3.9', 'Field Laptops',  null,  10080],
        ];

        foreach ($fleet as [$id, $host, $os, $ver, $group, $owner, $agoMinutes]) {
            Device::updateOrCreate(['rustdesk_id' => str_replace(' ', '', $id)], [
                'uuid' => base64_encode('demo-'.$host),
                'status' => Device::STATUS_ACTIVE,
                'hostname' => $host,
                'os' => $os,
                'version' => $ver,
                'username' => strtolower(explode('-', $host)[1] ?? 'user'),
                'device_group_id' => $groups[$group]->id,
                'user_id' => $owner ? $users[$owner]->id : null,
                'last_online_at' => now()->subMinutes($agoMinutes),
                'last_online_ip' => '198.51.100.'.random_int(10, 240),
            ]);
        }

        // --- Strategies --------------------------------------------------------
        Strategy::firstOrCreate(['name' => 'Retail tills — locked down'], [
            'note' => 'View-only for tills; no file transfer or clipboard.',
            'enabled' => true,
            'is_default' => false,
            'enforce' => true,
            'options' => ['enable-file-transfer' => 'N', 'enable-clipboard' => 'N', 'enable-audio' => 'N'],
        ]);

        Strategy::firstOrCreate(['name' => 'Standard workstation'], [
            'note' => 'Default policy for office machines.',
            'enabled' => true,
            'is_default' => true,
            'enforce' => false,
            'options' => ['enable-file-transfer' => 'Y', 'enable-clipboard' => 'Y'],
        ]);

        // --- Address book -------------------------------------------------------
        // Shared books still need an owner; use the first administrator.
        $owner = User::where('is_admin', true)->orderBy('id')->first() ?? $users['dana'];

        $book = AddressBook::firstOrCreate(
            ['name' => 'Retail Estate'],
            [
                'guid' => (string) \Illuminate\Support\Str::uuid(),
                'is_personal' => false,
                'owner_user_id' => $owner->id,
                'note' => 'Shared with the support team',
            ]
        );

        // RustDesk stores tag colours as u32 ARGB (0xAARRGGBB), not hex strings.
        $tags = [];
        foreach (['tills' => 'e8590c', 'back-office' => '1971c2', 'priority' => 'c92a2a'] as $tag => $rgb) {
            $tags[$tag] = Tag::firstOrCreate(
                ['address_book_id' => $book->id, 'name' => $tag],
                ['color' => (int) hexdec('ff'.$rgb)]
            );
        }

        foreach ([
            ['722806131', 'RN-TILL-01', 'tills'],
            ['788190664', 'RN-TILL-02', 'tills'],
            ['845337209', 'RN-BACKOFFICE', 'back-office'],
            ['901552418', 'RS-TILL-01', 'tills'],
            ['967118340', 'RS-STOCKROOM', 'priority'],
        ] as [$id, $alias, $tag]) {
            AddressBookEntry::updateOrCreate(
                ['address_book_id' => $book->id, 'rustdesk_id' => $id],
                ['alias' => $alias, 'tag_ids' => [$tags[$tag]->id], 'hostname' => $alias, 'platform' => 'Windows']
            );
        }

        // --- Logs ----------------------------------------------------------------
        $operators = ['dana' => 'Dana Whitfield', 'ravi' => 'Ravi Menon', 'imogen' => 'Imogen Clarke'];
        $connId = 1000;

        foreach (range(1, 40) as $i) {
            $dev = $fleet[array_rand($fleet)];
            $op = array_rand($operators);
            $started = now()->subMinutes(random_int(5, 60 * 24 * 12));
            $closed = (clone $started)->addMinutes(random_int(2, 90));

            AuditConnection::create([
                'action' => 'close',
                'conn_id' => $connId++,
                'rustdesk_id' => str_replace(' ', '', $dev[0]),
                'from_peer' => (string) random_int(100000000, 999999999),
                'from_name' => $operators[$op],
                'ip' => '192.0.2.'.random_int(10, 240),
                'conn_type' => 0,
                'closed_at' => $closed,
            ])->forceFill(['created_at' => $started])->save();
        }

        // A few sessions still open, so Active Sessions is not empty.
        foreach ([['722806131', 'Dana Whitfield'], ['112903776', 'Ravi Menon']] as [$rid, $who]) {
            AuditConnection::create([
                'action' => 'new', 'conn_id' => $connId++, 'rustdesk_id' => $rid,
                'from_peer' => (string) random_int(100000000, 999999999), 'from_name' => $who,
                'ip' => '192.0.2.'.random_int(10, 240), 'conn_type' => 0,
            ])->forceFill(['created_at' => now()->subMinutes(random_int(2, 25))])->save();
        }

        foreach (range(1, 12) as $i) {
            $dev = $fleet[array_rand($fleet)];
            AuditFileTransfer::create([
                'rustdesk_id' => str_replace(' ', '', $dev[0]),
                'from_peer' => (string) random_int(100000000, 999999999),
                'from_name' => $operators[array_rand($operators)],
                'path' => ['C:\\Reports\\month-end.xlsx', 'C:\\Installers\\agent-setup.msi', '/home/ops/logs.tar.gz'][array_rand([0, 1, 2])],
                'is_file' => true,
                'direction' => random_int(0, 1),
                'file_count' => random_int(1, 6),
                'ip' => '192.0.2.'.random_int(10, 240),
            ])->forceFill(['created_at' => now()->subMinutes(random_int(30, 60 * 24 * 9))])->save();
        }

        foreach (range(1, 25) as $i) {
            $ok = random_int(1, 5) > 1;
            $who = array_rand($operators);
            LoginLog::create([
                'user_id' => $ok ? ($users[$who]->id ?? null) : null,
                'username' => $who,
                'client' => 'Web',
                'ip' => '192.0.2.'.random_int(10, 240),
                'successful' => $ok,
                'note' => $ok ? null : 'Invalid password',
            ])->forceFill(['created_at' => now()->subMinutes(random_int(10, 60 * 24 * 6))])->save();
        }

        // Console-raised alarms use the 'console' sentinel and JSON info, the
        // same convention AlarmLog::console() applies in production.
        foreach ([
            [AlarmLog::TYP_BRUTE_FORCE, ['username' => 'ravi', 'ip' => '192.0.2.55']],
            [AlarmLog::TYP_SPRAYING, ['ip' => '192.0.2.140', 'attempts' => 24]],
        ] as [$typ, $info]) {
            AlarmLog::console($typ, $info)
                ->forceFill(['created_at' => now()->subHours(random_int(1, 72))])->save();
        }

        // Client-reported alarms carry the device's own id.
        foreach ([
            [1, '509238774', ['peer' => '192.0.2.31']],
            [2, '178224951', ['peer' => '192.0.2.88']],
        ] as [$typ, $rid, $info]) {
            AlarmLog::create([
                'rustdesk_id' => $rid, 'typ' => $typ, 'info' => json_encode($info),
            ])->forceFill(['created_at' => now()->subHours(random_int(1, 72))])->save();
        }

        $this->command->info(sprintf(
            'Done. %d devices, %d users, %d device groups, %d connections.',
            Device::count(), User::count(), DeviceGroup::count(), AuditConnection::count()
        ));
    }
}
