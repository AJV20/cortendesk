<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * First-boot seeder for container deployments: creates the initial admin
 * account and nothing else (no demo devices). Idempotent — safe to run on
 * every container start.
 */
class DockerSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        User::create([
            'username' => env('CORTENDESK_ADMIN_USER', 'admin'),
            'name' => 'Administrator',
            'password' => env('CORTENDESK_ADMIN_PASSWORD', 'changeme'),
            'is_admin' => true,
        ]);
    }
}
