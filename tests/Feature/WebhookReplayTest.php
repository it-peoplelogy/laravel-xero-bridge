<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Peoplelogy\XeroBridge\Webhooks\WebhookEventRecorder;

/**
 * Durable dedupe across Xero's 31-day replay window.
 *
 * The uniqueness lock covers a retry storm within minutes; this table is what
 * makes a replay DAYS later a no-op.
 */
function replayEvent(array $overrides = []): array
{
    return array_merge([
        'resourceUrl' => 'https://api.xero.com/api.xro/2.0/Invoices/inv-1',
        'resourceId' => 'inv-1',
        'eventDateUtc' => '2026-09-29T01:15:39.902',
        'eventType' => 'UPDATE',
        'eventCategory' => 'INVOICE',
        'tenantId' => 'tenant-1',
        'tenantType' => 'ORGANISATION',
    ], $overrides);
}

function deliver(array $events, string $entropy = 'aaa'): void
{
    (new ProcessXeroWebhook([
        'events' => $events,
        'firstEventSequence' => 1,
        'lastEventSequence' => count($events),
        'entropy' => $entropy,
    ]))->handle();
}

/**
 * Spy on the log the recorder writes to.
 *
 * The recorder is a singleton holding the logger it was built with, so it is
 * rebuilt after the spy is in place -- whatever resolved it earlier.
 */
function spyOnWebhookRecorderLog(): void
{
    Log::spy();

    app()->forgetInstance(WebhookEventRecorder::class);
}

beforeEach(function () {
    config()->set('xero-bridge.webhooks.dedupe.enabled', true);
    app(TableGuard::class)->flush();
    Event::fake([XeroWebhookReceived::class]);
});

/*
|--------------------------------------------------------------------------
| The point of the table
|--------------------------------------------------------------------------
*/

it('dispatches a new event and records it', function () {
    deliver([replayEvent()]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
    expect(XeroWebhookEvent::count())->toBe(1);
});

it('does not dispatch the same event twice', function () {
    // Xero stores undelivered events for up to 31 days and replays them. The
    // uniqueness lock is long gone by then.
    deliver([replayEvent()], 'first-delivery');
    deliver([replayEvent()], 'second-delivery-different-entropy');

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
});

it('counts the redelivery rather than inserting a second row', function () {
    deliver([replayEvent()]);
    deliver([replayEvent()]);
    deliver([replayEvent()]);

    expect(XeroWebhookEvent::count())->toBe(1);

    $row = XeroWebhookEvent::sole();

    // delivery_count > 1 is the only direct evidence available that the
    // 31-day window is genuinely being exercised in production.
    expect($row->delivery_count)->toBe(3)
        ->and($row->isReplay())->toBeTrue();
});

it('treats a later change to the same record as a new event', function () {
    // Xero sends the same resourceId again when it changes again. That is not
    // a replay, and suppressing it would lose a real update.
    deliver([replayEvent()]);
    deliver([replayEvent(['eventDateUtc' => '2026-09-29T02:00:00.000'])]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);
    expect(XeroWebhookEvent::count())->toBe(2);
});

it('separates the same resource id in two organisations', function () {
    deliver([replayEvent()]);
    deliver([replayEvent(['tenantId' => 'tenant-2'])]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);
});

it('dispatches only the events it has not seen from a mixed envelope', function () {
    deliver([replayEvent()]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);

    deliver([replayEvent(), replayEvent(['resourceId' => 'inv-2'])]);

    // The known one is skipped; the new one goes through.
    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);
    expect(XeroWebhookEvent::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Failing safe -- a missed notification is worse than a duplicate
|--------------------------------------------------------------------------
*/

it('dispatches normally when the feature is off', function () {
    config()->set('xero-bridge.webhooks.dedupe.enabled', false);

    deliver([replayEvent()]);
    deliver([replayEvent()]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);
    expect(XeroWebhookEvent::count())->toBe(0);
});

it('dispatches normally when the table has not been migrated', function () {
    // A consumer who upgrades and never publishes the migration must see
    // exactly the behaviour they had before.
    config()->set('xero-bridge.webhooks.dedupe.table', 'a_table_that_does_not_exist');
    app(TableGuard::class)->flush();

    deliver([replayEvent()]);
    deliver([replayEvent()]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);
});

/*
|--------------------------------------------------------------------------
| Ordering -- the design decision most easily got backwards
|--------------------------------------------------------------------------
*/

it('records only AFTER dispatching, so a crash cannot suppress an event', function () {
    // Claiming first would mean a worker killed between the claim and the
    // dispatch suppresses that notification permanently -- and Xero will not
    // send it again once the endpoint has 200'd. Dispatching first makes the
    // worst case a duplicate, which listeners are already required to tolerate.
    $rowsSeenByListener = null;

    Event::listen(XeroWebhookReceived::class, function () use (&$rowsSeenByListener) {
        $rowsSeenByListener = XeroWebhookEvent::count();
    });

    // Let the real listener above run. Not Event::fake([]), which fakes
    // EVERY event, so the listener would never have been called.
    Event::fakeExcept([XeroWebhookReceived::class]);

    deliver([replayEvent()]);

    expect($rowsSeenByListener)->toBe(0)
        ->and(XeroWebhookEvent::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Recording can fail; the job cannot
|--------------------------------------------------------------------------
*/

it('counts an event another worker recorded first, without a warning', function () {
    // Two workers can both pass the check before either inserts. The unique
    // index refuses the second row, and that is the constraint doing its job.
    $listenerRan = false;

    Event::listen(XeroWebhookReceived::class, function (XeroWebhookReceived $received) use (&$listenerRan) {
        $listenerRan = true;

        // The other worker's insert, landing between this worker's check
        // and its own insert.
        XeroWebhookEvent::create([
            'dedupe_key' => $received->event->dedupeKey(),
            'tenant_id' => $received->event->tenantId,
            'resource_id' => $received->event->resourceId,
            'event_type' => $received->event->eventType,
            'event_category' => $received->event->eventCategory,
            'event_date_utc' => $received->event->eventDateUtc,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'delivery_count' => 1,
        ]);
    });

    Event::fakeExcept([XeroWebhookReceived::class]);
    spyOnWebhookRecorderLog();

    deliver([replayEvent()]);

    expect($listenerRan)->toBeTrue()
        ->and(XeroWebhookEvent::sole()->delivery_count)->toBe(2);

    Log::shouldNotHaveReceived('warning');
});

it('never fails the job when the table cannot take the row', function () {
    // The listeners have already run. Throwing here failed the job, and every
    // retry dispatched the event again while the rest of the envelope waited
    // -- then went down with the job once its last attempt failed.
    Schema::create('broken_webhook_events', function (Blueprint $table) {
        $table->id();
    });

    config()->set('xero-bridge.webhooks.dedupe.table', 'broken_webhook_events');
    app(TableGuard::class)->flush();
    spyOnWebhookRecorderLog();

    deliver([replayEvent(), replayEvent(['resourceId' => 'inv-2'])]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);

    Log::shouldHaveReceived('warning')->twice()->withArgs(
        fn (string $message, array $context = []): bool => $message === 'xero-bridge: could not record a dispatched webhook event.',
    );
});

it('logs an insert that failed for a reason other than a duplicate', function () {
    // A column a host added without a default. SQLite and MySQL report it
    // with SQLSTATE 23000 -- the same code as a duplicate key -- so reading
    // the code counted it as a redelivery, recorded nothing, and said
    // nothing.
    Schema::create('strict_webhook_events', function (Blueprint $table) {
        $table->id();
        $table->string('dedupe_key', 64)->unique();
        $table->string('tenant_id');
        $table->string('resource_id');
        $table->string('event_type');
        $table->string('event_category');
        $table->string('event_date_utc');
        $table->timestamp('first_seen_at');
        $table->timestamp('last_seen_at');
        $table->unsignedInteger('delivery_count');
        $table->string('added_by_the_host');
    });

    config()->set('xero-bridge.webhooks.dedupe.table', 'strict_webhook_events');
    app(TableGuard::class)->flush();
    spyOnWebhookRecorderLog();

    deliver([replayEvent()]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
    expect(XeroWebhookEvent::count())->toBe(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context = []): bool => $message === 'xero-bridge: could not record a dispatched webhook event.'
            && str_contains($context['exception'], 'NOT NULL'),
    );
});

/*
|--------------------------------------------------------------------------
| Organisations with no stored connection (webhooks.unknown_tenants)
|--------------------------------------------------------------------------
*/

it('does not record an event it ignored', function () {
    // A row means "dispatched". Recording an ignored event would suppress it
    // for good once the organisation is connected and Xero replays it.
    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');

    deliver([replayEvent()]);

    Event::assertNotDispatched(XeroWebhookReceived::class);
    expect(XeroWebhookEvent::count())->toBe(0);

    connection(); // tenant-1 connects

    deliver([replayEvent()]);

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
    expect(XeroWebhookEvent::count())->toBe(1);
});

it('does not count a redelivery of an event it ignored', function () {
    // Recorded while the organisation was still connected here, then
    // forgotten. Its replays are skipped before the table is consulted, so
    // delivery_count keeps describing what listeners actually received.
    connection();
    deliver([replayEvent()]);
    XeroConnection::query()->delete();

    config()->set('xero-bridge.webhooks.unknown_tenants', 'ignore');

    deliver([replayEvent()], 'replayed-after-forget');

    expect(XeroWebhookEvent::sole()->delivery_count)->toBe(1);
    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
});

/*
|--------------------------------------------------------------------------
| Pruning
|--------------------------------------------------------------------------
*/

it('prunes rows past the retention window', function () {
    deliver([replayEvent()]);

    XeroWebhookEvent::query()->update(['first_seen_at' => now()->subDays(90)]);

    expect((new XeroWebhookEvent)->prunable()->count())->toBe(1);
});

it('never prunes inside Xero 31-day replay window, however it is configured', function () {
    // Pruning inside the window deletes exactly the rows that make a late
    // replay detectable, which is the only thing this table is for.
    config()->set('xero-bridge.webhooks.dedupe.retain_days', 5);

    deliver([replayEvent()]);

    XeroWebhookEvent::query()->update(['first_seen_at' => now()->subDays(20)]);

    expect((new XeroWebhookEvent)->prunable()->count())->toBe(0);
});
