<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\XeroConnected;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\Actor;

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

/** The one XeroConnected the callback dispatched. Needs Event::fake() first. */
function dispatchedConnectedEvent(): XeroConnected
{
    $dispatched = Event::dispatched(XeroConnected::class);

    expect($dispatched)->toHaveCount(1);

    return $dispatched->first()[0];
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

    // Nobody is signed in here (the suite drops `auth`), so there is no actor.
    Event::assertDispatched(XeroConnected::class, fn (XeroConnected $event) => $event->actor === null
        && $event->wasRepointed === false
        && $event->connection->is($connection));
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

it('rejects a valid state presented from another session', function () {
    // The reason the state lives in the session and not in a shared cache:
    // anyone who saw the authorise URL -- browser history, an error tracker's
    // breadcrumbs, a proxy log -- holds the state value. Bound to the session,
    // it is useless in any other browser, so they cannot finish the flow with
    // a code for an organisation of their own and have it stored under the
    // key the administrator chose.
    $state = startFlow();
    $issuingSession = session()->all();

    // Another browser: the same state value, a session that never issued it.
    $this->flushSession();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertRedirect('/')
        ->assertSessionHas('xero-bridge.error');

    expect(session('xero-bridge.error'))->toContain('could not be verified')
        ->and(XeroConnection::count())->toBe(0);

    Http::assertNothingSent();

    // The value itself was sound, and the attempt did not use it up: back in
    // the session it was issued to, it still connects.
    $this->flushSession()->withSession($issuingSession);

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertSessionHas('xero-bridge.status');

    expect(XeroConnection::sole()->tenant_id)->toBe('tenant-1');
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

/*
|--------------------------------------------------------------------------
| Who connected
|--------------------------------------------------------------------------
|
| XeroConnected carries the signed-in user as an Actor: plain scalars, never
| the model. Package events have no SerializesModels, so a model on the event
| would put the password hash and remember token into every queued listener's
| payload.
|
*/

it('records who completed the consent', function () {
    Event::fake([XeroConnected::class]);
    $this->actingAs(new GenericUser(['id' => 42]));

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertSessionHas('xero-bridge.status');

    $actor = dispatchedConnectedEvent()->actor;

    expect($actor)->toBeInstanceOf(Actor::class)
        ->and($actor->id)->toBe(42)
        ->and($actor->type)->toBe(GenericUser::class)
        ->and($actor->guard)->toBe('web');
});

it('keeps a string user key, such as a ULID, as the same string', function () {
    Event::fake([XeroConnected::class]);
    $this->actingAs(new GenericUser(['id' => '01J9ZQ3K8M2N4P6R8T0V2X4Z6B']));

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(dispatchedConnectedEvent()->actor?->id)->toBe('01J9ZQ3K8M2N4P6R8T0V2X4Z6B');
});

it('names the guard the user signed in through', function () {
    Event::fake([XeroConnected::class]);
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);

    // What the auth middleware does for `auth:staff`: sign the user in on that
    // guard and make it the default for the rest of the request.
    $this->actingAs(new GenericUser(['id' => 5]), 'staff');

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    expect(dispatchedConnectedEvent()->actor?->guard)->toBe('staff');
});

it('never puts the user model, or its password hash, on the event', function () {
    Event::fake([XeroConnected::class]);

    $user = (new User)->forceFill([
        'id' => 7,
        'password' => 'password-hash-sentinel',
        'remember_token' => 'remember-token-sentinel',
    ]);

    // Proof the check below can fail: serialising the model itself -- which is
    // what a queued listener would get if the event carried it -- leaks both.
    expect(serialize($user))->toContain('password-hash-sentinel')
        ->toContain('remember-token-sentinel');

    $this->actingAs($user);

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}");

    $event = dispatchedConnectedEvent();

    expect(serialize($event))->not->toContain('password-hash-sentinel')
        ->not->toContain('remember-token-sentinel')
        ->and($event->actor?->id)->toBe(7)
        ->and($event->actor?->type)->toBe(User::class);
});

it('still connects when the signed-in user cannot be resolved', function () {
    Event::fake([XeroConnected::class]);

    $state = startFlow();
    fakeTokenAndConnections();

    // A guard whose user lookup throws: a user provider whose database is down.
    Auth::viaRequest('xero-bridge-broken', fn () => throw new RuntimeException('user provider is down'));
    config()->set('auth.guards.broken', ['driver' => 'xero-bridge-broken']);
    Auth::shouldUse('broken');

    expect(fn () => Auth::user())->toThrow(RuntimeException::class, 'user provider is down');

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertRedirect('/')
        ->assertSessionHas('xero-bridge.status');

    expect(XeroConnection::sole()->tenant_id)->toBe('tenant-1')
        ->and(dispatchedConnectedEvent()->actor)->toBeNull();
});

it('still shows success when a XeroConnected listener throws', function () {
    // The row is committed before the event fires, so a failing listener -- an
    // audit table that was never migrated -- must not show the administrator
    // a 500 for a connection that worked.
    Exceptions::fake();
    Log::spy();

    Event::listen(XeroConnected::class, function () {
        throw new RuntimeException('audit table missing');
    });

    $state = startFlow();
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertRedirect('/')
        ->assertSessionHas('xero-bridge.status')
        ->assertSessionMissing('xero-bridge.error');

    expect(XeroConnection::sole()->tenant_id)->toBe('tenant-1');

    // Reported, so it is as visible as any other application error...
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'audit table missing');

    // ...and logged against the connection it happened to.
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'XeroConnected listener failed')
            && ($context['connection'] ?? null) === 'default'
            && ($context['exception'] ?? null) === 'audit table missing');
});

/*
|--------------------------------------------------------------------------
| on_tenant_conflict=rekey
|--------------------------------------------------------------------------
|
| The organisation just authorised is already stored under ANOTHER key, and
| the policy moves that row to the key being connected. When the target key
| is free that is a rename; when it holds a different organisation, moving
| onto it is a repoint of that key, and on_key_conflict decides it.
|
*/

it('re-keys an organisation into a free key', function () {
    config()->set('xero-bridge.on_tenant_conflict', 'rekey');
    Event::fake([XeroConnected::class]);

    $stored = connection(['key' => 'beta', 'tenant_id' => 'tenant-1']);

    // XeroConnected does not name the key the row left, so an observer on the
    // model's update is how a host sees 'beta' stop resolving.
    $renamedFrom = null;
    Event::listen('eloquent.updated: '.XeroConnection::class, function (XeroConnection $row) use (&$renamedFrom) {
        $renamedFrom = $row->getOriginal('key');
    });

    $state = startFlow('acme');
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertSessionHas('xero-bridge.status');

    $moved = XeroConnection::sole();

    // The same row under its new key, with the fresh tokens.
    expect($moved->getKey())->toBe($stored->getKey())
        ->and($moved->key)->toBe('acme')
        ->and($moved->tenant_id)->toBe('tenant-1')
        ->and($moved->refresh_token)->toBe('refresh-1')
        ->and($renamedFrom)->toBe('beta')
        ->and(dispatchedConnectedEvent()->wasRepointed)->toBeFalse();
});

it('re-keys over an occupied key under on_key_conflict=replace, removing the occupant', function () {
    // on_key_conflict stays at its shipped default, 'replace'.
    config()->set('xero-bridge.on_tenant_conflict', 'rekey');
    Event::fake([XeroConnected::class, 'eloquent.deleted: '.XeroConnection::class]);

    $occupant = connection(['key' => 'acme', 'tenant_id' => 'tenant-a', 'tenant_name' => 'Org A']);
    $stored = connection(['key' => 'beta', 'tenant_id' => 'tenant-1']);

    $state = startFlow('acme');
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertSessionHas('xero-bridge.status');

    $moved = XeroConnection::sole();

    expect($moved->getKey())->toBe($stored->getKey())
        ->and($moved->key)->toBe('acme')
        ->and($moved->tenant_id)->toBe('tenant-1')
        ->and(XeroConnection::find($occupant->getKey()))->toBeNull()
        ->and(dispatchedConnectedEvent()->wasRepointed)->toBeTrue();

    // An Eloquent instance delete, so an observer on the model sees it go.
    Event::assertDispatched(
        'eloquent.deleted: '.XeroConnection::class,
        fn (string $name, XeroConnection $deleted) => $deleted->tenant_id === 'tenant-a',
    );
});

it('refuses to re-key over an occupied key under on_key_conflict=error, and changes nothing', function () {
    config()->set('xero-bridge.on_tenant_conflict', 'rekey');
    config()->set('xero-bridge.on_key_conflict', 'error');
    Event::fake([XeroConnected::class]);

    connection(['key' => 'acme', 'tenant_id' => 'tenant-a', 'tenant_name' => 'Org A', 'refresh_token' => 'refresh-a']);
    connection(['key' => 'beta', 'tenant_id' => 'tenant-1', 'refresh_token' => 'refresh-b']);

    $state = startFlow('acme');
    fakeTokenAndConnections();

    $this->get("/xero/callback?code=the-code&state={$state}")
        ->assertRedirect('/')
        ->assertSessionHas('xero-bridge.error');

    expect(session('xero-bridge.error'))
        ->toContain('Connection [acme] is already bound to the Xero organisation "Org A"')
        ->toContain('cannot be repointed at "Acme Sdn Bhd"');

    // Both rows exactly as they were: no delete, no move, no new tokens.
    $acme = XeroConnection::where('key', 'acme')->sole();
    $beta = XeroConnection::where('key', 'beta')->sole();

    expect(XeroConnection::count())->toBe(2)
        ->and($acme->tenant_id)->toBe('tenant-a')
        ->and($acme->refresh_token)->toBe('refresh-a')
        ->and($beta->tenant_id)->toBe('tenant-1')
        ->and($beta->refresh_token)->toBe('refresh-b');

    Event::assertNotDispatched(XeroConnected::class);
});
