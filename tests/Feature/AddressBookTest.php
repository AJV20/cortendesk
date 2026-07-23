<?php

use App\Livewire\AddressBookManager;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::create([
        'username' => 'abtester',
        'name' => 'AB Tester',
        'email' => 'abtester@example.com',
        'password' => 'secret-password',
        'is_admin' => true,
        'is_active' => true,
    ]);
});

it('renders the address books page', function () {
    $this->actingAs($this->user)
        ->get('/address-books')
        ->assertOk()
        ->assertSeeLivewire(AddressBookManager::class);

    // The personal address book is auto-created on mount.
    expect(AddressBook::where('owner_user_id', $this->user->id)->where('is_personal', true)->exists())->toBeTrue();
});

it('defaults admins to the shared tab and syncs the tab with the selected book', function () {
    $shared = AddressBook::create(['name' => 'Ops Team', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    $component = Livewire::actingAs($this->user)->test(AddressBookManager::class);
    $personal = AddressBook::where('owner_user_id', $this->user->id)->where('is_personal', true)->first();

    // Admin lands on the shared tab with the first shared book selected.
    $component->assertSet('tab', 'shared')
        ->assertSet('selectedBookId', $shared->id)
        ->assertSee('Ops Team');

    // Personal tab labels books by owner username, not the default book name.
    $component->call('setTab', 'personal')
        ->assertSet('tab', 'personal')
        ->assertSet('selectedBookId', $personal->id)
        ->assertSee('abtester');

    // Selecting a shared book flips the tab back.
    $component->call('selectBook', $shared->id)
        ->assertSet('tab', 'shared');
});

it('falls back to the personal tab when an admin has no shared books', function () {
    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->assertSet('tab', 'personal');
});

it('defaults non-admins to the personal tab scoped to their own book', function () {
    $member = User::create([
        'username' => 'abmember',
        'name' => 'AB Member',
        'email' => 'abmember@example.com',
        'password' => 'secret-password',
        'is_admin' => false,
        'is_active' => true,
    ]);

    // A shared book with no rules must stay invisible to the non-admin.
    AddressBook::create(['name' => 'Admins Only', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    Livewire::actingAs($member)
        ->test(AddressBookManager::class)
        ->assertSet('tab', 'personal')
        ->assertSet('selectedBookId', AddressBook::where('owner_user_id', $member->id)->where('is_personal', true)->value('id'))
        ->assertDontSee('Admins Only');
});

it('creates a shared address book', function () {
    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('openNewBook')
        ->set('bookName', 'Ops Team')
        ->set('bookNote', 'Shared ops machines')
        ->call('createBook')
        ->assertHasNoErrors();

    $book = AddressBook::where('name', 'Ops Team')->first();
    expect($book)->not->toBeNull()
        ->and($book->is_personal)->toBeFalse()
        ->and($book->note)->toBe('Shared ops machines')
        ->and($book->guid)->not->toBeEmpty()
        ->and($book->owner_user_id)->toBe($this->user->id);
});

it('adds an entry with tags', function () {
    $book = AddressBook::create(['name' => 'Shared', 'owner_user_id' => $this->user->id, 'is_personal' => false]);
    $tagA = Tag::create(['address_book_id' => $book->id, 'name' => 'A', 'color' => 0xFFE53935]);
    $tagB = Tag::create(['address_book_id' => $book->id, 'name' => 'B', 'color' => 0xFF1E88E5]);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openAddEntry')
        ->set('entryRustdeskId', '123456789')
        ->set('entryAlias', 'Test rig')
        ->set('entryTagIds', [(string) $tagA->id, (string) $tagB->id])
        ->call('saveEntry')
        ->assertHasNoErrors();

    $entry = AddressBookEntry::where('address_book_id', $book->id)->where('rustdesk_id', '123456789')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->alias)->toBe('Test rig')
        ->and($entry->tag_ids)->toEqualCanonicalizing([$tagA->id, $tagB->id]);
});

it('round-trips tag colors between hex and u32 ARGB', function () {
    // hex → u32: opaque alpha ORed in
    expect(AddressBookManager::hexToColor('#2196F3'))->toBe(0xFF2196F3)
        ->and(AddressBookManager::hexToColor('#000000'))->toBe(0xFF000000)
        ->and(AddressBookManager::hexToColor('#FFFFFF'))->toBe(0xFFFFFFFF);

    // u32 → hex
    expect(AddressBookManager::colorToHex(0xFF2196F3))->toBe('#2196F3')
        ->and(AddressBookManager::colorToHex(AddressBookManager::hexToColor('#AB12CD')))->toBe('#AB12CD');

    // 0 (unset) renders as default gray
    expect(AddressBookManager::colorToHex(0))->toBe('#6C757D');

    // Full roundtrip through the component's addTag action
    $book = AddressBook::create(['name' => 'Colors', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openAddTag')
        ->set('tagName', 'Hot')
        ->set('tagColor', '#E53935')
        ->call('addTag')
        ->assertHasNoErrors();

    $tag = Tag::where('address_book_id', $book->id)->where('name', 'Hot')->first();
    expect($tag->color)->toBe(0xFFE53935)
        ->and(AddressBookManager::colorToHex($tag->color))->toBe('#E53935');
});

it('does not allow deleting a personal address book', function () {
    $personal = AddressBook::personalFor($this->user);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $personal->id)
        ->call('deleteBook');

    expect(AddressBook::whereKey($personal->id)->exists())->toBeTrue();
});

it('strips a deleted tag from entry tag_ids', function () {
    $book = AddressBook::create(['name' => 'Strip', 'owner_user_id' => $this->user->id, 'is_personal' => false]);
    $doomed = Tag::create(['address_book_id' => $book->id, 'name' => 'Doomed', 'color' => 0xFF43A047]);
    $kept = Tag::create(['address_book_id' => $book->id, 'name' => 'Kept', 'color' => 0xFF1E88E5]);

    $entry = AddressBookEntry::create([
        'address_book_id' => $book->id,
        'rustdesk_id' => '987654321',
        'tag_ids' => [$doomed->id, $kept->id],
    ]);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('deleteTag', $doomed->id);

    expect(Tag::whereKey($doomed->id)->exists())->toBeFalse()
        ->and($entry->refresh()->tag_ids)->toEqual([$kept->id]);
});
