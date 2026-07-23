<?php

use App\Models\AddressBook;
use App\Models\User;

it('serves the webclient bootstrap script without auth', function () {
    $this->get('/webclient-config/index.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        ->assertSee("localStorage.setItem('api-server'", false)
        ->assertSee('http://localhost', false);
});

it('returns server config with the personal book as peer cards', function () {
    config([
        'cortendesk.id_server' => '198.51.100.7',
        'cortendesk.public_key' => 'test-public-key=',
    ]);

    $user = apiUser(['username' => 'webby']);
    $book = AddressBook::personalFor($user);
    $book->entries()->create([
        'rustdesk_id' => '123456789',
        'alias' => 'Studio PC',
        'hostname' => 'STUDIO-01',
        'platform' => 'Windows',
        'username' => 'operator',
        'hash' => 'abc123hash',
    ]);

    $this->postJson('/api/server-config', [], bearerFor($user))
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('data.id_server', '198.51.100.7')
        ->assertJsonPath('data.key', 'test-public-key=')
        ->assertJsonPath('data.peers.123456789.info.hostname', 'STUDIO-01')
        ->assertJsonPath('data.peers.123456789.info.id', '123456789')
        ->assertJsonPath('data.peers.123456789.view-style', 'shrink');
});

it('returns an empty peers object for a user with no entries', function () {
    $user = apiUser(['username' => 'empty-webby']);

    $response = $this->postJson('/api/server-config', [], bearerFor($user))->assertOk();

    // Must serialize as {} (object), not [] — the client indexes it by peer id.
    expect($response->getContent())->toContain('"peers":{}');
});

it('rejects server-config without a token', function () {
    $this->postJson('/api/server-config')->assertStatus(401);
});
