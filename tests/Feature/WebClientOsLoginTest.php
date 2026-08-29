<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebClientOsLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebClientOsLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://console.example.test']);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/webclient/os-login?peerId=123456')->assertRedirect('/login');
        $this->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'remote-os-secret',
        ])->assertRedirect('/login');
        $this->deleteJson('/webclient/os-login', ['peerId' => '123456'])->assertRedirect('/login');
    }

    public function test_password_is_encrypted_scoped_and_returned_only_with_no_store(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'remote-os-secret',
        ])->assertNoContent();

        $raw = (string) DB::table('webclient_os_logins')->value('password');
        $this->assertNotSame('remote-os-secret', $raw);
        $this->assertStringNotContainsString('remote-os-secret', $raw);

        $stored = WebClientOsLogin::query()->sole();
        $this->assertSame('remote-os-secret', $stored->password);
        $this->assertArrayNotHasKey('password', $stored->toArray());

        $this->actingAs($owner)
            ->getJson('/webclient/os-login?peerId=123456')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['enabled' => true, 'password' => 'remote-os-secret']);

        $this->actingAs($other)
            ->getJson('/webclient/os-login?peerId=123456')
            ->assertOk()
            ->assertExactJson(['enabled' => false]);
    }

    public function test_update_and_delete_are_idempotent_without_echoing_the_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'password' => 'first-secret',
        ])->assertNoContent()->assertDontSee('first-secret');

        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'password' => 'replacement-secret',
        ])->assertNoContent()->assertDontSee('replacement-secret');

        $this->assertDatabaseCount('webclient_os_logins', 1);
        $this->assertSame('replacement-secret', WebClientOsLogin::query()->sole()->password);

        $this->actingAs($user)
            ->deleteJson('/webclient/os-login', ['peerId' => 'abc-123'])
            ->assertNoContent();
        $this->actingAs($user)
            ->deleteJson('/webclient/os-login', ['peerId' => 'abc-123'])
            ->assertNoContent();
        $this->assertDatabaseCount('webclient_os_logins', 0);
    }

    public function test_plain_http_non_loopback_requests_are_refused(): void
    {
        $user = User::factory()->create();
        $this->withServerVariables([
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
            'HTTP_HOST' => 'console.example.test',
            'REMOTE_ADDR' => '192.0.2.10',
        ])->actingAs($user);

        $this->getJson('/webclient/os-login?peerId=123456')->assertForbidden();
        $this->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'test-only-password',
        ])->assertForbidden();
        $this->deleteJson('/webclient/os-login', ['peerId' => '123456'])->assertForbidden();
        $this->assertDatabaseCount('webclient_os_logins', 0);
    }

    public function test_forged_forwarded_proto_cannot_promote_raw_http(): void
    {
        $user = User::factory()->create();
        $this->withServerVariables([
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
            'HTTP_HOST' => 'console.example.test',
            'REMOTE_ADDR' => '172.18.0.25',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->withHeader('X-Forwarded-Proto', 'https')->actingAs($user);

        $this->getJson('/webclient/os-login?peerId=123456')->assertForbidden();
        $this->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'test-only-password',
        ])->assertForbidden();
        $this->assertDatabaseCount('webclient_os_logins', 0);
    }

    public function test_default_ingress_does_not_promote_arbitrary_forwarded_proto(): void
    {
        $this->assertSame(['127.0.0.1', '::1'], config('trustedproxy.proxies'));
        $nginx = file_get_contents(base_path('docker/nginx.conf.template'));
        $this->assertIsString($nginx);
        $this->assertStringNotContainsString('map $http_x_forwarded_proto', $nginx);
        $this->assertStringNotContainsString('fastcgi_param HTTPS', $nginx);
    }

    public function test_peer_and_password_boundaries_fail_closed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => '123 456',
            'password' => 'secret',
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => '',
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => str_repeat('x', 1025),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('webclient_os_logins', 0);
    }
}
