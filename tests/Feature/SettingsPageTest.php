<?php

use App\Livewire\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function actingSettingsAdmin(): User
{
    $user = User::create([
        'username' => 'settings-admin',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($user);

    return $user;
}

it('defaults the Build Installers URL from config', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->assertSet('rdgenUrl', config('cortendesk.rdgen_url'));
});

it('saves a custom Build Installers URL', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('rdgenUrl', 'https://rdgen.example.com/')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('rdgen_url'))->toBe('https://rdgen.example.com');
});

it('rejects a non-URL Build Installers value', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('rdgenUrl', 'not a url')
        ->call('save')
        ->assertHasErrors(['rdgenUrl' => 'url']);
});

it('links the sidebar entry to the configured URL', function () {
    actingSettingsAdmin();
    Setting::put('rdgen_url', 'https://rdgen.example.com');

    $this->get('/')->assertSee('https://rdgen.example.com')
        ->assertSee('Build Installers');
});

it('hides the sidebar entry when the URL is empty', function () {
    actingSettingsAdmin();
    Setting::put('rdgen_url', '');

    $this->get('/')->assertDontSee('Build Installers');
});
