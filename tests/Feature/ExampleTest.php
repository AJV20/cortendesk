<?php

use App\Models\User;

it('redirects guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('shows the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Sign In');
});

it('uses forwarded HTTPS only from a trusted proxy', function () {
    $trusted = $this->withServerVariables([
        'HTTP_HOST' => 'cortendesk.test',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->get('/login');

    $trusted->assertOk()
        ->assertSee('action="https://cortendesk.test/login"', escape: false);

    $untrusted = $this->withServerVariables([
        'HTTP_HOST' => 'cortendesk.test',
        'REMOTE_ADDR' => '192.0.2.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->get('/login');

    $untrusted->assertOk()
        ->assertSee('action="http://cortendesk.test/login"', escape: false);
});

it('renders the overview dashboard with charts and live tiles', function () {
    $user = User::create(['username' => 'dash', 'password' => 'secret-password']);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('chart-connections')
        ->assertSee('chart-platforms')
        ->assertSee('chart-versions')
        ->assertSee('Active Sessions');
});
