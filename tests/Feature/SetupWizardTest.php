<?php

namespace Tests\Feature;

use App\Livewire\SetupWizard;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_users_with_settings_access_can_open_setup(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->get('/setup')->assertOk();
        $this->actingAs($user)->get('/setup')->assertRedirect('/');
    }

    public function test_wizard_uses_canonical_client_values_and_never_displays_private_keys(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::put('id_server', 'id.example.test:21116');
        Setting::put('relay_server', 'relay.example.test:21117');
        Setting::put('public_key', 'PUBLIC_KEY');
        config(['app.url' => 'https://desk.example.test']);

        Livewire::actingAs($admin)
            ->test(SetupWizard::class)
            ->assertSee('id.example.test:21116')
            ->assertSee('relay.example.test:21117')
            ->assertSee('https://desk.example.test')
            ->assertSee('PUBLIC_KEY')
            ->assertSee('Copy all')
            ->assertSee('ID Server: id.example.test:21116')
            ->assertDontSee('APP_KEY')
            ->assertDontSee('id_ed25519');
    }

    public function test_fresh_empty_install_is_prompted_and_dismissal_is_persisted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue(SetupWizard::shouldPrompt($admin));

        Livewire::actingAs($admin)->test(SetupWizard::class)->call('dismiss');

        $this->assertNotNull($admin->fresh()->setup_wizard_dismissed_at);
        $this->assertFalse(SetupWizard::shouldPrompt($admin->fresh()));
    }

    public function test_a_device_record_without_a_heartbeat_does_not_complete_setup(): void
    {
        $admin = User::factory()->admin()->create();
        Device::create(['rustdesk_id' => '123456', 'uuid' => 'imported-only']);

        Livewire::actingAs($admin)
            ->test(SetupWizard::class)
            ->assertSet('deviceConnected', false)
            ->call('complete')
            ->assertHasErrors('device');

        $this->assertNull($admin->fresh()->setup_wizard_completed_at);
    }

    public function test_first_device_heartbeat_completes_the_wizard(): void
    {
        $admin = User::factory()->admin()->create();
        Device::create(['rustdesk_id' => '123456', 'uuid' => 'uuid-1', 'last_online_at' => now()]);

        Livewire::actingAs($admin)
            ->test(SetupWizard::class)
            ->assertSet('deviceConnected', true)
            ->call('complete');

        $this->assertNotNull($admin->fresh()->setup_wizard_completed_at);
        $this->assertFalse(SetupWizard::shouldPrompt($admin->fresh()));
    }
}
