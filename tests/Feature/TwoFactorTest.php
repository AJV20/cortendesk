<?php

use App\Livewire\TwoFactorSettings;
use App\Livewire\UserList;
use App\Models\ConsoleAudit;
use App\Models\Setting;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/** Compute a valid TOTP for a secret at the current (frozen) time. */
function totpFor(string $secret): string
{
    $timestep = intdiv(now()->timestamp, TwoFactor::PERIOD);

    return TwoFactor::totp($secret, 'x')->at($timestep * TwoFactor::PERIOD);
}

/** Enable 2FA on a user with a known secret; returns [user, secret]. */
function enableTwoFactor(User $user): array
{
    $secret = TwoFactor::generateSecret();
    $user->forceFill([
        'totp_secret' => $secret,
        'totp_enabled' => true,
        'totp_confirmed_at' => now(),
        'totp_last_timestep' => null,
    ])->save();

    foreach (TwoFactor::generateRecoveryCodes() as $code) {
        $user->recoveryCodes()->create(['code_hash' => Hash::make($code)]);
    }

    return [$user->fresh(), $secret];
}

// --- Enrollment ------------------------------------------------------------

it('enrolls a user through the wizard (happy path)', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSettings::class)
        ->call('startSetup')
        ->assertSet('settingUp', true);

    $secret = session('2fa_setup_secret');
    expect($secret)->not->toBeEmpty();

    $component->set('confirmCode', totpFor($secret))
        ->call('confirmSetup')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->totp_enabled)->toBeTrue()
        ->and($user->totp_secret)->toBe($secret)          // decrypts back
        ->and($user->totp_confirmed_at)->not->toBeNull()
        ->and($user->recoveryCodes()->count())->toBe(10);

    // Recovery codes surfaced once, correct format, and session scrubbed.
    expect($component->get('recoveryCodes'))->toHaveCount(10);
    expect($component->get('recoveryCodes')[0])->toMatch('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/');
    expect(session('2fa_setup_secret'))->toBeNull();

    expect(ConsoleAudit::where('action', 'user.2fa-enable')->exists())->toBeTrue();
});

it('rejects a wrong confirmation code during enrollment', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TwoFactorSettings::class)
        ->call('startSetup')
        ->set('confirmCode', '000000')
        ->call('confirmSetup')
        ->assertHasErrors('confirmCode');

    expect($user->fresh()->totp_enabled)->toBeFalse();
});

// --- Login challenge -------------------------------------------------------

it('logs in directly when 2FA is not enabled', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password'])
        ->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user);
});

it('issues a challenge (does not log in) when 2FA is enabled', function () {
    [$user] = enableTwoFactor(User::factory()->create(['password' => Hash::make('secret-password')]));

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password'])
        ->assertRedirect(route('login.2fa'));

    $this->assertGuest();
    expect(session('2fa.user_id'))->toBe($user->id);
});

it('passes the challenge with a valid TOTP inside the window', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    [$user, $secret] = enableTwoFactor(User::factory()->create(['password' => Hash::make('secret-password')]));

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);

    $this->post('/login/2fa', ['code' => totpFor($secret)])
        ->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user);
});

it('rejects an invalid TOTP at the challenge', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    [$user] = enableTwoFactor(User::factory()->create(['password' => Hash::make('secret-password')]));

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);

    $this->post('/login/2fa', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

// --- Replay guard ----------------------------------------------------------

it('rejects a replayed TOTP (already-used timestep)', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    [$user, $secret] = enableTwoFactor(User::factory()->create(['password' => Hash::make('secret-password')]));

    // First use succeeds.
    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);
    $code = totpFor($secret);
    $this->post('/login/2fa', ['code' => $code])->assertRedirect(route('overview'));

    expect($user->fresh()->totp_last_timestep)->not->toBeNull();

    // Log out, log back in, and try the SAME code (same timestep) again.
    $this->post('/logout');
    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);

    $this->post('/login/2fa', ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

// --- Recovery codes --------------------------------------------------------

it('accepts a recovery code once and warns when few remain', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);
    $secret = TwoFactor::generateSecret();
    $user->forceFill([
        'totp_secret' => $secret,
        'totp_enabled' => true,
        'totp_confirmed_at' => now(),
    ])->save();

    // Exactly 3 codes so consuming one leaves 2 (the low-count threshold).
    $plain = ['AAAAA-BBBBB', 'CCCCC-DDDDD', 'EEEEE-FFFFF'];
    foreach ($plain as $c) {
        $user->recoveryCodes()->create(['code_hash' => Hash::make($c)]);
    }

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);
    $this->post('/login/2fa', ['code' => 'AAAAA-BBBBB'])
        ->assertRedirect(route('overview'))
        ->assertSessionHas('twofactor_warning');

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->recoveryCodes()->whereNull('used_at')->count())->toBe(2);

    // The same code cannot be used a second time.
    $this->post('/logout');
    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);
    $this->post('/login/2fa', ['code' => 'AAAAA-BBBBB'])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

// --- Admin reset -----------------------------------------------------------

it('lets an admin reset a user\'s 2FA and audits it', function () {
    $admin = User::factory()->admin()->create();
    [$target] = enableTwoFactor(User::factory()->create());

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('resetTwoFactor', $target->id);

    $target->refresh();
    expect($target->totp_enabled)->toBeFalse()
        ->and($target->totp_secret)->toBeNull()
        ->and($target->totp_last_timestep)->toBeNull()
        ->and($target->recoveryCodes()->count())->toBe(0);

    expect(ConsoleAudit::where('action', 'user.2fa-reset')->exists())->toBeTrue();
});

it('resets 2FA via the break-glass CLI command', function () {
    [$user] = enableTwoFactor(User::factory()->create(['username' => 'lockedout']));

    $this->artisan('cortendesk:2fa-reset lockedout')->assertSuccessful();

    expect($user->fresh()->totp_enabled)->toBeFalse()
        ->and($user->fresh()->recoveryCodes()->count())->toBe(0);
});

// --- Enforcement -----------------------------------------------------------

it('redirects an un-enrolled user to setup when 2FA is required', function () {
    Setting::put('two_factor_required', '1');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/devices')
        ->assertRedirect(route('account.two-factor'));

    // The setup screen itself is reachable.
    $this->actingAs($user)->get(route('account.two-factor'))->assertOk();
});

it('enforces 2FA for admins only when configured', function () {
    Setting::put('two_factor_required_admins', '1');

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/')->assertRedirect(route('account.two-factor'));

    $plain = User::factory()->create();
    $this->actingAs($plain)->get('/')->assertOk();
});

it('does not enforce 2FA on an already-enrolled user', function () {
    Setting::put('two_factor_required', '1');
    [$user] = enableTwoFactor(User::factory()->create());

    $this->actingAs($user)->get('/')->assertOk();
});

// --- Pending expiry --------------------------------------------------------

it('expires the pending 2FA marker after 5 minutes', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    [$user, $secret] = enableTwoFactor(User::factory()->create(['password' => Hash::make('secret-password')]));

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);

    // Jump past the 5-minute window.
    Carbon::setTestNow('2026-07-24 12:06:00');

    $this->get('/login/2fa')->assertRedirect(route('login'));
    $this->post('/login/2fa', ['code' => totpFor($secret)])->assertRedirect(route('login'));

    $this->assertGuest();
});

// --- Rate limiting ---------------------------------------------------------

it('rate-limits the password step', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['username' => $user->username, 'password' => 'wrong']);
    }

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rate-limits the 2FA step', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    [$user, $secret] = enableTwoFactor(User::factory()->create(['password' => Hash::make('secret-password')]));

    $this->post('/login', ['username' => $user->username, 'password' => 'secret-password']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login/2fa', ['code' => '000000']);
    }

    // Even the correct code is now blocked by the throttle.
    $this->post('/login/2fa', ['code' => totpFor($secret)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});
