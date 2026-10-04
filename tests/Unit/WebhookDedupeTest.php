<?php

declare(strict_types=1);

use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ApcWrapper;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Webhooks\WebhookEvent;

/**
 * Replay dedupe, at the two levels the package can reach without a table.
 *
 * Xero stores undelivered events for up to 31 days and replays them, and the
 * controller dispatches the envelope job BEFORE returning its 200 -- so a
 * response that misses Xero's five-second budget means the job is already
 * queued when Xero retries.
 */
function webhookEvent(array $overrides = []): WebhookEvent
{
    return WebhookEvent::fromArray(array_merge([
        'resourceUrl' => 'https://api.xero.com/api.xro/2.0/Invoices/inv-1',
        'resourceId' => 'inv-1',
        'eventDateUtc' => '2026-09-29T01:15:39.902',
        'eventType' => 'UPDATE',
        'eventCategory' => 'INVOICE',
        'tenantId' => 'tenant-1',
        'tenantType' => 'ORGANISATION',
    ], $overrides));
}

function envelopePayload(array $events, string $entropy = 'aaa'): array
{
    return [
        'events' => $events,
        'firstEventSequence' => 1,
        'lastEventSequence' => count($events),
        'entropy' => $entropy,
    ];
}

/*
|--------------------------------------------------------------------------
| The key itself
|--------------------------------------------------------------------------
*/

it('gives the same event the same key across deliveries', function () {
    expect(webhookEvent()->dedupeKey())->toBe(webhookEvent()->dedupeKey());
});

it('separates events that differ in any of the four parts', function (array $overrides) {
    expect(webhookEvent($overrides)->dedupeKey())->not->toBe(webhookEvent()->dedupeKey());
})->with([
    'another organisation' => [['tenantId' => 'tenant-2']],
    'another resource' => [['resourceId' => 'inv-2']],
    'create rather than update' => [['eventType' => 'CREATE']],
    'a later change to the same record' => [['eventDateUtc' => '2026-09-29T01:15:40.000']],
]);

it('ignores entropy, which differs between deliveries of the same event', function () {
    // This is the trap the key exists to avoid. Xero varies `entropy` so the
    // payload signature differs between retries; keying on it would make every
    // replay look new, which is worse than having no key at all.
    $first = new ProcessXeroWebhook(envelopePayload([webhookEvent()->toArray()], 'entropy-one'));
    $second = new ProcessXeroWebhook(envelopePayload([webhookEvent()->toArray()], 'entropy-two'));

    expect($first->uniqueId())->toBe($second->uniqueId());
});

it('does not collide across resources by concatenation', function () {
    // "tenant-1|inv-12" and "tenant-1|inv-1" + "2..." must not hash alike.
    expect(webhookEvent(['resourceId' => 'inv-12'])->dedupeKey())
        ->not->toBe(webhookEvent(['resourceId' => 'inv-1', 'eventType' => '2UPDATE'])->dedupeKey());
});

/*
|--------------------------------------------------------------------------
| The job
|--------------------------------------------------------------------------
*/

it('is a unique job', function () {
    // Without this, the controller's dispatch-before-200 means Xero's retry
    // queues a second identical job and every listener fires twice. The
    // interface alone takes no lock on the controller's path, though: the
    // controller takes it, see tests/Feature/WebhookUniqueLockTest.php.
    expect(new ProcessXeroWebhook(envelopePayload([webhookEvent()->toArray()])))
        ->toBeInstanceOf(ShouldBeUnique::class);
});

it('takes its lock in the store XERO_LOCK_STORE names', function () {
    // The store token refreshes lock in, so one setting makes both hold
    // across servers.
    config()->set('cache.stores.webhook_locks', ['driver' => 'array']);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_locks');

    expect((new ProcessXeroWebhook(envelopePayload([])))->uniqueVia())
        ->toBe(Cache::store('webhook_locks'));
});

it('takes its lock in the default store while XERO_LOCK_STORE is unset', function (?string $value) {
    config()->set('xero-bridge.tokens.lock_store', $value);

    // Never null: Laravel 11 and early 12 use uniqueVia()'s return value
    // without a fallback.
    expect((new ProcessXeroWebhook(envelopePayload([])))->uniqueVia())
        ->toBe(Cache::store());
})->with(['unset' => [null], 'empty' => ['']]);

it('falls back to the default store rather than throwing', function () {
    // Laravel calls uniqueVia() again in the worker to release the lock,
    // after the listeners ran. Throwing there would fail the attempt, and
    // every retry would dispatch the envelope again.
    config()->set('xero-bridge.tokens.lock_store', 'no-such-store');

    expect((new ProcessXeroWebhook(envelopePayload([])))->uniqueVia())
        ->toBe(Cache::store());
});

it('falls back to the default store when the named one cannot lock', function () {
    // The apc store has no locks; releasing through it in the worker would
    // throw for the same reason as above. Built, never used, so the apcu
    // extension is not needed.
    Cache::extend('webhook_nolock', fn () => new Repository(new ApcStore(new ApcWrapper)));
    config()->set('cache.stores.webhook_nolock', ['driver' => 'webhook_nolock']);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_nolock');

    expect(Cache::store('webhook_nolock')->getStore())->not->toBeInstanceOf(LockProvider::class)
        ->and((new ProcessXeroWebhook(envelopePayload([])))->uniqueVia())->toBe(Cache::store());
});

it('falls back to the default store when the named one is the null store', function () {
    // It does implement LockProvider, which is the trap: every lock it hands
    // out is granted at once and excludes nothing, so taking the uniqueness
    // lock there would switch it off without a word.
    config()->set('cache.stores.webhook_null', ['driver' => 'null']);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_null');

    expect(Cache::store('webhook_null')->getStore())->toBeInstanceOf(LockProvider::class)
        ->and((new ProcessXeroWebhook(envelopePayload([])))->uniqueVia())->toBe(Cache::store());
});

it('still runs a job queued before this release', function () {
    // Everything 1.4.x serialized of its own into a queued job is the
    // payload. Nothing added since may depend on more -- a new constructor
    // property would be uninitialised here, and reading it is a fatal Error.
    $class = ProcessXeroWebhook::class;
    $property = "\0{$class}\0payload";
    $payload = envelopePayload([webhookEvent()->toArray()]);

    $job = unserialize(sprintf(
        'O:%d:"%s":1:{s:%d:"%s";%s}',
        strlen($class),
        $class,
        strlen($property),
        $property,
        serialize($payload),
    ));

    Event::fake([XeroWebhookReceived::class]);

    $job->handle();

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);

    expect($job->uniqueId())->toBe((new ProcessXeroWebhook($payload))->uniqueId())
        ->and($job->uniqueVia())->toBe(Cache::store());
});

it('keys the lock on every event in the envelope', function () {
    $one = new ProcessXeroWebhook(envelopePayload([webhookEvent()->toArray()]));

    $two = new ProcessXeroWebhook(envelopePayload([
        webhookEvent()->toArray(),
        webhookEvent(['resourceId' => 'inv-2'])->toArray(),
    ]));

    expect($one->uniqueId())->not->toBe($two->uniqueId());
});

it('holds the lock long enough to cover a retry, and no longer by default', function () {
    expect((new ProcessXeroWebhook(envelopePayload([])))->uniqueFor())->toBe(900);
});

it('refuses a uniqueFor short enough to be useless', function () {
    config()->set('xero-bridge.webhooks.unique_for', 5);

    expect((new ProcessXeroWebhook(envelopePayload([])))->uniqueFor())->toBe(60);
});

it('retries a failing listener instead of losing the envelope', function () {
    // Before 1.4.0 the job set no $tries, so Laravel's default of one attempt
    // applied: one failing listener and the delivery was gone.
    $job = new ProcessXeroWebhook(envelopePayload([]));

    expect($job->tries())->toBe(5)
        ->and($job->backoff())->toBe([10, 30, 120, 600]);
});

it('honours a configured attempt count', function () {
    config()->set('xero-bridge.webhooks.tries', 2);

    expect((new ProcessXeroWebhook(envelopePayload([])))->tries())->toBe(2);
});
