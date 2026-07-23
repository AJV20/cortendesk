<?php

namespace Database\Seeders;

use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class AddressBookSampleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'admin')->first()
            ?? User::where('is_admin', true)->orderBy('id')->first();

        if (! $admin) {
            $this->command?->warn('No admin user found — skipping address book sample data.');

            return;
        }

        /* ------------------- Personal book for admin ------------------- */

        $personal = AddressBook::personalFor($admin);

        $tags = collect([
            ['name' => 'Production', 'color' => 0xFFE53935], // red
            ['name' => 'Office', 'color' => 0xFF1E88E5],     // blue
            ['name' => 'Lab', 'color' => 0xFF43A047],        // green
        ])->map(fn (array $t) => Tag::firstOrCreate(
            ['address_book_id' => $personal->id, 'name' => $t['name']],
            ['color' => $t['color']],
        ));

        [$production, $office, $lab] = [$tags[0], $tags[1], $tags[2]];

        $personalEntries = [
            ['rustdesk_id' => '128776901', 'alias' => 'Build server', 'platform' => 'linux', 'username' => 'ci', 'tag_ids' => [$production->id, $lab->id]],
            ['rustdesk_id' => '354112087', 'alias' => 'Front desk PC', 'platform' => 'windows', 'username' => 'reception', 'tag_ids' => [$office->id]],
            ['rustdesk_id' => '467299310', 'alias' => 'Design iMac', 'platform' => 'macos', 'username' => 'petra', 'tag_ids' => [$office->id]],
            ['rustdesk_id' => '583370126', 'alias' => 'Warehouse kiosk', 'platform' => 'windows', 'username' => 'kiosk', 'tag_ids' => [$production->id]],
            ['rustdesk_id' => '691148850', 'alias' => 'Test tablet', 'platform' => 'android', 'username' => 'qa', 'tag_ids' => [$lab->id]],
        ];

        foreach ($personalEntries as $e) {
            $personal->entries()->firstOrCreate(
                ['rustdesk_id' => $e['rustdesk_id']],
                ['alias' => $e['alias'], 'platform' => $e['platform'], 'username' => $e['username'], 'tag_ids' => $e['tag_ids']],
            );
        }

        /* --------------------- Shared book: NYSE Floor --------------------- */

        $shared = AddressBook::firstOrCreate(
            ['name' => 'NYSE Floor', 'is_personal' => false],
            ['owner_user_id' => $admin->id, 'note' => 'Trading floor workstations'],
        );

        $trading = Tag::firstOrCreate(
            ['address_book_id' => $shared->id, 'name' => 'Trading'],
            ['color' => 0xFFFFB300], // amber
        );
        $backoffice = Tag::firstOrCreate(
            ['address_book_id' => $shared->id, 'name' => 'Backoffice'],
            ['color' => 0xFF8E24AA], // purple
        );

        $sharedEntries = [
            ['rustdesk_id' => '812234509', 'alias' => 'Booth 12 terminal', 'platform' => 'windows', 'username' => 'trader12', 'tag_ids' => [$trading->id]],
            ['rustdesk_id' => '823901447', 'alias' => 'Booth 14 terminal', 'platform' => 'windows', 'username' => 'trader14', 'tag_ids' => [$trading->id]],
            ['rustdesk_id' => '834578120', 'alias' => 'Settlements desk', 'platform' => 'windows', 'username' => 'settle01', 'tag_ids' => [$backoffice->id]],
            ['rustdesk_id' => '845660934', 'alias' => 'Compliance laptop', 'platform' => 'macos', 'username' => 'compliance', 'tag_ids' => [$backoffice->id, $trading->id]],
        ];

        foreach ($sharedEntries as $e) {
            $shared->entries()->firstOrCreate(
                ['rustdesk_id' => $e['rustdesk_id']],
                ['alias' => $e['alias'], 'platform' => $e['platform'], 'username' => $e['username'], 'tag_ids' => $e['tag_ids']],
            );
        }

        AddressBookRule::firstOrCreate(
            ['address_book_id' => $shared->id, 'subject_type' => 'everyone', 'subject_id' => null],
            ['permission' => AddressBookRule::PERM_READ],
        );
    }
}
