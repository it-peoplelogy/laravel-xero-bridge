<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;
use Peoplelogy\XeroBridge\Webhooks\WebhookEvent;

/**
 * webhooks.unknown_tenants: events for an organisation with no stored
 * connection here.
 *
 * Xero delivers events for every organisation connected to the Xero app, so
 * an organisation connected from another environment, or forgotten here but
 * never disconnected at Xero, keeps sending them. `dispatch` (the default)
 * hands them to listeners like any other; `ignore` skips them first.
 */

/** One event, as it sits in an envelope's `events` array. */
function tenantFilterEvent(string $tenantId, string $resourceId, array $overrides = []): array
{
    return array_merge([
        'resourceUrl' => "https://api.xero.com/api.xro/2.0/Invoices/{$resourceId}",
        'resourceId' => $resourceId,
        'eventDateUtc' => '2026-09-28T01:15:39.902',
        'eventType' => 'UPDATE',
        'eventCategory' => 'INVOICE',
        'tenantId' => $tenantId,
        'tenantType' => 'ORGANISATION',
    ], $overrides);
}

/** @param list<array<string, mixed>> $events */
function tenantFilterJob(array $events): ProcessXeroWebhook
{
    return new ProcessXeroWebhook([
        'events' => $events,
        'firstEventSequence' => 1,
        'lastEventSequence' => count($events),
        'entropy' => 'S0m3r4Nd0mt3xt',
    ]);
}

/**
 * The resource ids handed to listeners, in dispatch order.
 *
 * @return list<string>
 */
function tenantFilterDispatched(): array
{
    return Event::dispatched(XeroWebhookReceived::class)
        ->map(fn (array $arguments): string => $arguments[0]->resourceId())
        ->values()
        ->all();
}

beforeEach(function () {
    Event::fake([XeroWebhookReceived::class]);
});

/*
|--------------------------------------------------------------------------
| dispatch -- the default, and every release before 1.5.0
|--------------------------------------------------------------------------
*/

it('dispatches events for an organisation with no stored connection by default', function () {
    tenantFilterJob([
        tenantFilterEvent('tenant-elsewhere', 'inv-1'),
        tenantFilterEvent('tenant-elsewhere', 'inv-2'),
    ])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1', 'inv-2']);
});

it('looks nothing up unless the filter is on', function () {
    // Consumers who never asked for the filter pay no query for it.
    $this->mock(ConnectionRepository::class)->shouldNotReceive('findByTenantId');

    tenantFilterJob([tenantFilterEvent('tenant-elsewhere', 'inv-1')])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
});

it('dispatches under a config published before unknown_tenants existed', function () {
    // The webhooks block of a 1.4.x config/xero-bridge.php, cached before the
    // update and so never filled in: the key is simply absent.
    config()->set('xero-bridge.webhooks', [
        'enabled' => true,
        'path' => 'webhook',
        'queue' => null,
        'connection' => null,
        'unique_for' => 900,
        'tries' => 5,
        'dedupe' => ['enabled' => false, 'table' => 'xero_webhook_events', 'retain_days' => 45],
    ]);

    expect(config()->has('xero-bridge.webhooks.unknown_tenants'))->toBeFalse();

    tenantFilterJob([tenantFilterEvent('tenant-elsewhere', 'inv-1')])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
});

it('treats any value but ignore as dispatch', function (mixed $value) {
    // A filter switched on by a misspelling would drop real notifications.
    config()->set('xero-bridge.webhooks.unknown_tenants', $value);

    tenantFilterJob([tenantFilterEvent('tenant-elsewhere', 'inv-1')])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
})->with([
    'a typo' => ['ignroe'],
    'another word' => ['skip'],
    'empty' => [''],
    'null' => [null],
    'a boolean' => [true],
]);

/*
|--------------------------------------------------------------------------
| ignore
|--------------------------------------------------------------------------
*/

it('skips events for an organisation with no stored connection, keeping the order of the rest', function () {
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    connection(); // tenant-1
    Log::spy();

    tenantFilterJob([
        tenantFilterEvent('tenant-1', 'inv-1'),
        tenantFilterEvent('tenant-2', 'inv-2'),
        tenantFilterEvent('tenant-1', 'inv-3'),
        tenantFilterEvent('tenant-3', 'inv-4'),
        tenantFilterEvent('tenant-2', 'inv-5'),
        tenantFilterEvent('tenant-1', 'inv-6'),
    ])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1', 'inv-3', 'inv-6']);

    // ONE line for the envelope, not one per event.
    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'no stored connection')
            && $context['tenant_ids'] === ['tenant-2', 'tenant-3']
            && $context['count'] === 3,
    );
});

it('logs nothing when nothing was skipped', function () {
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    connection();
    Log::spy();

    tenantFilterJob([tenantFilterEvent('tenant-1', 'inv-1')])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
    Log::shouldNotHaveReceived('info');
});

it('reads ignore in any case and with stray spaces', function (string $value) {
    config()->set('xero-bridge.webhooks.unknown_tenants', $value);

    tenantFilterJob([tenantFilterEvent('tenant-elsewhere', 'inv-1')])->handle();

    Event::assertNotDispatched(XeroWebhookReceived::class);
})->with(['ignore', 'IGNORE', ' Ignore ']);

it('never skips an App Store subscription event', function (string $tenantType) {
    // Xero sends these with tenantType APPLICATION and the app's own id
    // where a tenant id would be, so no stored row can ever match one.
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    $this->mock(ConnectionRepository::class)->shouldNotReceive('findByTenantId');

    tenantFilterJob([
        tenantFilterEvent('the-application-id', 'subscription-1', [
            'tenantType' => $tenantType,
            'eventCategory' => 'SUBSCRIPTION',
            'resourceUrl' => 'https://api.xero.com/appstore/2.0/subscriptions/subscription-1',
        ]),
    ])->handle();

    expect(tenantFilterDispatched())->toBe(['subscription-1']);
})->with(['APPLICATION', 'Application']);

it('treats an invalidated connection as known', function () {
    // The organisation IS connected here and only needs a reconnect; its
    // listeners keep hearing about it.
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    connection(['invalidated_at' => now(), 'invalidated_reason' => 'invalid_grant']);

    tenantFilterJob([tenantFilterEvent('tenant-1', 'inv-1')])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
});

it('checks only that the connection exists, never decrypting its tokens', function () {
    // isUsable() reads the refresh token, which decrypts it. A row whose
    // ciphertext no longer decrypts (a rotated APP_KEY) would make that
    // throw; checking existence only cannot.
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    connection();
    XeroConnection::query()->update(['refresh_token' => 'not-a-ciphertext']);

    tenantFilterJob([tenantFilterEvent('tenant-1', 'inv-1')])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
});

it('dispatches anyway when the lookup fails, and asks again for the next event', function () {
    // A database blip must not turn into lost notifications.
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    Log::spy();

    $this->mock(ConnectionRepository::class)
        ->shouldReceive('findByTenantId')
        ->twice()
        ->with('tenant-1')
        ->andThrow(new RuntimeException('db down'));

    tenantFilterJob([
        tenantFilterEvent('tenant-1', 'inv-1'),
        tenantFilterEvent('tenant-1', 'inv-2'),
    ])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1', 'inv-2']);

    Log::shouldHaveReceived('warning')->twice()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'could not look up the organisation')
            && $context['tenant_id'] === 'tenant-1'
            && $context['exception'] === 'db down',
    );
});

it('looks each organisation up once per delivery', function () {
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');

    $repository = $this->mock(ConnectionRepository::class);
    $repository->shouldReceive('findByTenantId')->once()->with('tenant-1')->andReturn(new XeroConnection);
    $repository->shouldReceive('findByTenantId')->once()->with('tenant-2')->andReturnNull();

    tenantFilterJob([
        tenantFilterEvent('tenant-1', 'inv-1'),
        tenantFilterEvent('tenant-2', 'inv-2'),
        tenantFilterEvent('tenant-1', 'inv-3'),
        tenantFilterEvent('tenant-2', 'inv-4'),
        tenantFilterEvent('tenant-2', 'inv-5'),
    ])->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1', 'inv-3']);
});

it('sees an organisation connected since an earlier delivery', function () {
    // Remembering answers beyond one run would hide, for the life of the
    // worker, an organisation connected while it ran. Same job instance
    // twice, so neither a static nor a property can be carrying the answer.
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');
    $job = tenantFilterJob([tenantFilterEvent('tenant-1', 'inv-1')]);

    $job->handle();

    Event::assertNotDispatched(XeroWebhookReceived::class);

    connection(); // tenant-1 connects

    $job->handle();

    expect(tenantFilterDispatched())->toBe(['inv-1']);
});

/*
|--------------------------------------------------------------------------
| connection() -- the same answer, for a listener to ask itself
|--------------------------------------------------------------------------
*/

it('gives a listener the stored connection of the event organisation', function () {
    $stored = connection();
    $received = new XeroWebhookReceived(
        WebhookEvent::fromArray(tenantFilterEvent('tenant-1', 'inv-1')),
        WebhookEnvelope::fromArray(['events' => [tenantFilterEvent('tenant-1', 'inv-1')]]),
    );

    expect($received->connection())->toBeInstanceOf(XeroConnection::class)
        ->and($received->connection()?->is($stored))->toBeTrue();
});

it('gives null for an organisation with no stored connection', function () {
    connection(); // tenant-1 only

    $received = new XeroWebhookReceived(
        WebhookEvent::fromArray(tenantFilterEvent('tenant-2', 'inv-1')),
        WebhookEnvelope::fromArray(['events' => [tenantFilterEvent('tenant-2', 'inv-1')]]),
    );

    expect($received->connection())->toBeNull();
});

it('adds nothing to the event a queued listener receives', function () {
    // An event serialized for a queued listener before the deploy must still
    // unserialize after it, so connection() is a lookup, never a property.
    connection();

    $received = new XeroWebhookReceived(
        WebhookEvent::fromArray(tenantFilterEvent('tenant-1', 'inv-1')),
        WebhookEnvelope::fromArray(['events' => [tenantFilterEvent('tenant-1', 'inv-1')]]),
    );

    $before = serialize($received);

    $received->connection();

    expect(serialize($received))->toBe($before)
        ->and(array_keys(get_object_vars($received)))->toBe(['event', 'envelope']);
});
