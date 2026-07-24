<?php

use App\Livewire\ConsoleAuditList;
use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Livewire\SettingsPage;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

function auditAdmin(): User
{
    return User::create([
        'username' => 'audit-admin',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
}

/* -------------------------------------------------------------------------
 | ConsoleAudit::record()
 * ---------------------------------------------------------------------- */

it('records an audit row attributed to the acting user with ip', function () {
    $admin = auditAdmin();

    $this->actingAs($admin);
    ConsoleAudit::record('user.create', 'Created user bob', 'user', 'bob');

    $row = ConsoleAudit::first();
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($admin->id)
        ->and($row->username)->toBe('audit-admin')
        ->and($row->action)->toBe('user.create')
        ->and($row->target_type)->toBe('user')
        ->and($row->target_id)->toBe('bob')
        ->and($row->ip)->not->toBeNull();
});

it('is a no-op when unauthenticated', function () {
    ConsoleAudit::record('user.create', 'Created user bob', 'user', 'bob');

    expect(ConsoleAudit::count())->toBe(0);
});

/* -------------------------------------------------------------------------
 | Instrumented actions write audit rows
 * ---------------------------------------------------------------------- */

it('records group.create when a group is created', function () {
    Livewire::actingAs(auditAdmin())
        ->test(GroupList::class)
        ->call('create', 'devices')
        ->set('name', 'Sales')
        ->call('save')
        ->assertHasNoErrors();

    expect(ConsoleAudit::where('action', 'group.create')->exists())->toBeTrue();
});

it('records device.delete when a device is deleted', function () {
    $device = Device::create([
        'rustdesk_id' => '123123123',
        'uuid' => 'uuid-del',
        'hostname' => 'host-del',
        'os' => 'Windows 10',
        'username' => 'user',
        'version' => '1.4.2',
    ]);

    Livewire::actingAs(auditAdmin())
        ->test(DeviceList::class)
        ->call('deleteDevice', $device->id);

    $row = ConsoleAudit::where('action', 'device.delete')->first();
    expect($row)->not->toBeNull()
        ->and($row->target_id)->toBe('123123123');
});

it('records settings.update when settings are saved', function () {
    Livewire::actingAs(auditAdmin())
        ->test(SettingsPage::class)
        ->set('onlineWindow', 60)
        ->set('logRetentionDays', 365)
        ->call('save')
        ->assertHasNoErrors();

    expect(ConsoleAudit::where('action', 'settings.update')->exists())->toBeTrue();
});

/* -------------------------------------------------------------------------
 | /logs/console route gating
 * ---------------------------------------------------------------------- */

it('redirects guests from the console audit page', function () {
    $this->get(route('logs.console'))->assertRedirect(route('login'));
});

it('bounces non-admins from the console audit page', function () {
    $user = User::create([
        'username' => 'plain-user',
        'password' => 'secret-password',
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('logs.console'))
        ->assertRedirect(route('overview'));
});

it('renders the console audit page for an admin with rows', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('group.create', 'Created device group Sales', 'group', 'Sales');

    $this->get(route('logs.console'))
        ->assertOk()
        ->assertSeeLivewire(ConsoleAuditList::class)
        ->assertSee('Sales');
});

/* -------------------------------------------------------------------------
 | Filters and export
 * ---------------------------------------------------------------------- */

it('filters by action and operator search', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('group.create', 'Created device group Sales', 'group', 'Sales');
    ConsoleAudit::record('device.delete', 'Deleted device 999888777', 'device', '999888777');

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->set('action', 'group.create')
        ->assertSee('Sales')
        ->assertDontSee('999888777');

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->set('search', '999888777')
        ->assertSee('999888777')
        ->assertDontSee('Sales');
});

it('exports the console audit as csv', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('settings.update', 'Updated server settings', 'settings', null);

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->call('export')
        ->assertFileDownloaded('console-audit.csv');
});
