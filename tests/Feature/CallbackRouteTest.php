<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Events\XeroConnected;
use Peoplelogy\XeroBridge\Models\XeroConnection;

/** Start a real connect request so the session holds a valid state value. */
function startFlow(string $key = 'default'): string
{
    $response = test()->get("/xero/connect/{$key}");

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}

function fakeTokenAndConnections(?array $connections = null, array $token = []): void
{
    Http::fake([
        'identity.xero.com/connect/token' => Http::response(array_merge([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 1800,
            'token_type' => 'Bearer',
            'scope' => 'openid profile email offline_access accounting.invoices',
        ], $token)),

        // Note: a BARE ARRAY, which is what Xero actually returns.
        'api.xero.com/connections' => Http::response($connections ?? [[
            'id' => 'conn-1',
            'authEventId' => 'auth-1',
            'tenantId' => 'tenant-1',
            'tenantType' => 'ORGANISATION',
            'tenantName' => 'Acme Sdn Bhd',
            'createdDateUtc' => '2026-01-01T00:00:00',
            'updatedDateUtc' => '2026-01-02T00:00:00',
        ]]),
    ]);
}

it('stores the connection on the happy path', function () {
    Event::fake([XeroConnected::class]);
    $state = startFlow();
    fakeTokenAndConnections();

    $response = $this->get("/xero/callback?code=the-code&state={$state}");

    $response->assertRedirect('/');
    $response->assertSessionHas('xero-bridge.status');

    $connection = XeroConnection::sole();

    expect($connection->key)->toBe('default')
        ->and($connection->tenant_id)->toBe('tenant-1')
        ->and($connection->connection_id)->toBe('conn-1')
        ->and($connection->tenant_name)->toBe('Acme Sdn Bhd')
        ->and($connection->access_token)->toBe('access-1')
        ->and($connection->refresh_token)->toBe('refresh-1')
        ->and($connection->expires_at->timestamp)->toBe(now()->addSeconds(1800)->timestamp);

    Event::assertDispatched(XeroConnected::class);
});

it('sends the token request form encoded with basic auth', function () {
    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'identity.xero.com')) {
            return false;
        }

        $expected = 'Basic '.base64_encode('test-client-id:test-client-secret');

        return $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['redirect_uri'] === 'https://example.test/xero/callback'
            && $request->hasHeader('Authorization', $expected);
    });
});

it('rejects a state that was never issued', function () {
    fakeTokenAndConnections();

    $response = $this->get('/xero/callback?code=the-code&state=forged');

    $response->assertRedirect('/');
    $response->assertSessionHas('xero-bridge.error');

    expect(XeroConnection::count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects a replayed state', function () {
    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");
    expect(XeroConnection::count())->toBe(1);

    // Same state a second time: the entry was consumed on first use.
    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertSessionHas('xero-bridge.error');

    expect(XeroConnection::count())->toBe(1);
});

it('handles the user cancelling without calling Xero', function () {
    $state = startFlow();
    fakeTokenAndConnections();

    $response = $this->get("/xero/callback?error=access_denied&state={$state}");

    $response->assertSessionHas('xero-bridge.error');
    expect(session('xero-bridge.error'))->toContain('cancelled');
    expect(XeroConnection::count())->toBe(0);

    Http::assertNothingSent();
});

it('reports a missing authorisation code', function () {
    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?state={$state}")->assertSessionHas('xero-bridge.error');

    expect(XeroConnection::count())->toBe(0);
});

it('reports an expired authorisation code and never lists connections', function () {
    $state = startFlow();

    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'code expired',
        ], 400),
        'api.xero.com/connections' => Http::response([]),
    ]);

    $this->get("/xero/callback?code=stale&state={$state}")
        ->assertSessionHas('xero-bridge.error');

    expect(XeroConnection::count())->toBe(0);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.xero.com/connections'));
});

it('refuses a token response with no refresh token', function () {
    $state = startFlow();
    fakeTokenAndConnections(token: ['refresh_token' => null]);

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(session('xero-bridge.error'))->toContain('offline_access')
        ->and(XeroConnection::count())->toBe(0);
});

it('refuses when no ORGANISATION tenant was authorised', function () {
    $state = startFlow();
    fakeTokenAndConnections([[
        'id' => 'conn-9',
        'tenantId' => 'tenant-9',
        // One word, no separator -- and not usable with the Accounting API.
        'tenantType' => 'PRACTICEMANAGER',
        'tenantName' => null,
    ]]);

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(session('xero-bridge.error'))->toContain('organisation')
        ->and(XeroConnection::count())->toBe(0);
});

it('accepts an organisation with a null tenant name', function () {
    $state = startFlow();
    fakeTokenAndConnections([[
        'id' => 'conn-2',
        'tenantId' => 'tenant-2',
        'tenantType' => 'ORGANISATION',
        'tenantName' => null,
    ]]);

    $this->get("/xero/callback?code=the-code&state={$state}");

    $connection = XeroConnection::sole();

    expect($connection->tenant_name)->toBeNull()
        ->and(session('xero-bridge.status'))->toContain('tenant-2');
});

it('prefers an organisation not already claimed by another key', function () {
    // Xero returns EVERY tenant the token can reach, not only the one just
    // authorised, so more than one ORGANISATION is the normal case.
    connection(['key' => 'existing', 'tenant_id' => 'tenant-taken']);

    $state = startFlow('fresh');
    fakeTokenAndConnections([
        ['id' => 'c1', 'tenantId' => 'tenant-taken', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Taken', 'updatedDateUtc' => '2026-05-01T00:00:00'],
        ['id' => 'c2', 'tenantId' => 'tenant-free', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Free', 'updatedDateUtc' => '2026-01-01T00:00:00'],
    ]);

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(XeroConnection::where('key', 'fresh')->sole()->tenant_id)->toBe('tenant-free');
});

it('falls back to the most recently authorised organisation', function () {
    $state = startFlow();
    fakeTokenAndConnections([
        ['id' => 'c1', 'tenantId' => 'tenant-old', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Old', 'updatedDateUtc' => '2026-01-01T00:00:00'],
        ['id' => 'c2', 'tenantId' => 'tenant-new', 'tenantType' => 'ORGANISATION', 'tenantName' => 'New', 'updatedDateUtc' => '2026-09-01T00:00:00'],
    ]);

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(XeroConnection::sole()->tenant_id)->toBe('tenant-new');
});

it('reconnecting the same organisation clears previous failure state', function () {
    connection([
        'key' => 'default',
        'tenant_id' => 'tenant-1',
        'invalidated_at' => now()->subDay(),
        'invalidated_reason' => 'invalid_grant',
        'failure_count' => 7,
    ]);

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    $connection = XeroConnection::sole();

    expect($connection->invalidated_at)->toBeNull()
        ->and($connection->invalidated_reason)->toBeNull()
        ->and($connection->failure_count)->toBe(0)
        ->and($connection->refresh_token)->toBe('refresh-1');
});

it('refuses to connect an organisation already held under another key', function () {
    // The default on_tenant_conflict is 'error': silently re-keying would
    // break every caller that already references the old key.
    connection(['key' => 'acme', 'tenant_id' => 'tenant-1']);

    $state = startFlow('other');
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(session('xero-bridge.error'))->toContain('already connected as [acme]')
        ->and(XeroConnection::count())->toBe(1);
});

it('repoints a key at a new organisation when the policy allows it', function () {
    connection(['key' => 'default', 'tenant_id' => 'tenant-old']);

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(XeroConnection::count())->toBe(1)
        ->and(XeroConnection::sole()->tenant_id)->toBe('tenant-1');
});

it('refuses to repoint a key when the policy forbids it', function () {
    config()->set('xero-bridge.on_key_conflict', 'error');
    connection(['key' => 'default', 'tenant_id' => 'tenant-old', 'tenant_name' => 'Old Org']);

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(session('xero-bridge.error'))->toContain('Old Org')
        ->and(XeroConnection::sole()->tenant_id)->toBe('tenant-old');
});
