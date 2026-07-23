<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::create([
            'username' => 'admin',
            'name' => 'Administrator',
            'password' => 'changeme',
            'is_admin' => true,
        ]);

        $groups = collect(['Office', 'Servers', 'Field Laptops'])
            ->map(fn ($name) => DeviceGroup::create(['name' => $name]));

        $samples = [
            ['123456789', 'DESKTOP-4K2M9', 'Windows 10 Pro 22H2', 'jsmith', '1.4.2', 'Front Desk', 0, true],
            ['987654321', 'MacBook-Pro-M2', 'macOS 14.5', 'jsmith', '1.4.2', 'Design MBP', 0, true],
            ['456123789', 'ubuntu-relay01', 'Ubuntu 22.04.4 LTS', 'root', '1.4.1', 'Relay Server', 1, true],
            ['321654987', 'WIN-SRV2019', 'Windows Server 2019', 'administrator', '1.3.9', 'File Server', 1, false],
            ['789321456', 'fedora-dev', 'Fedora Linux 40', 'developer', '1.4.2', null, 1, false],
            ['147258369', 'Galaxy-S24', 'Android 14', 'samsung', '1.4.2', 'Field Phone', 2, true],
            ['963852741', 'THINKPAD-X1', 'Windows 11 Pro 23H2', 'tech1', '1.4.0', 'Tech Laptop 1', 2, false],
            ['852741963', 'imac-lobby', 'macOS 13.6', 'reception', '1.3.7', 'Lobby iMac', 0, false],
        ];

        foreach ($samples as [$id, $host, $os, $user, $ver, $alias, $groupIdx, $online]) {
            Device::create([
                'rustdesk_id' => $id,
                'uuid' => fake()->uuid(),
                'hostname' => $host,
                'os' => $os,
                'cpu' => 'Intel(R) Core(TM) i7, 8 cores',
                'memory' => '16 GB',
                'username' => $user,
                'version' => $ver,
                'alias' => $alias,
                'device_group_id' => $groups[$groupIdx]->id,
                'last_online_at' => $online
                    ? now()->subSeconds(rand(5, 40))
                    : now()->subHours(rand(2, 200)),
                'last_online_ip' => fake()->ipv4(),
            ]);
        }
    }
}
