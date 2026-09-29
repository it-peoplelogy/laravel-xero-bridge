<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Support\TableGuard;

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
    Event::listen(XeroWebhookReceived::class, function () {
        expect(XeroWebhookEvent::count())->toBe(0);
    });

    Event::fake([]);   // let the real listener above run

    deliver([replayEvent()]);

    expect(XeroWebhookEvent::count())->toBe(1);
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
