<?php

use App\Livewire\UserList;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('renders the users page for an admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/users')
        ->assertOk()
        ->assertSeeLivewire(UserList::class);
});

it('creates a user from the modal', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('create')
        ->assertSet('showModal', true)
        ->set('username', 'jdoe')
        ->set('name', 'Jane Doe')
        ->set('email', 'jane@example.com')
        ->set('is_admin', false)
        ->set('is_active', true)
        ->set('password', 'supersecret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $user = User::where('username', 'jdoe')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email)->toBe('jane@example.com')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('supersecret', $user->password))->toBeTrue();
});

it('requires a password when creating a user', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'nopass')
        ->set('password', '')
        ->call('save')
        ->assertHasErrors(['password' => 'required']);
});

it('prevents deleting yourself', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('deleteUser', $admin->id);

    expect(User::find($admin->id))->not->toBeNull();
});

it('prevents disabling yourself', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('toggleActive', $admin->id);

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('keeps the password when editing with a blank password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['password' => 'original-pass']);
    $originalHash = $user->fresh()->getAuthPassword();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('edit', $user->id)
        ->assertSet('editing', $user->id)
        ->assertSet('username', $user->username)
        ->assertSet('password', '')
        ->set('name', 'Renamed Person')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Renamed Person')
        ->and($user->getAuthPassword())->toBe($originalHash)
        ->and(Hash::check('original-pass', $user->password))->toBeTrue();
});
