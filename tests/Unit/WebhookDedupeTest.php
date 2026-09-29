<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
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
    // queues a second identical job and every listener fires twice.
    expect(new ProcessXeroWebhook(envelopePayload([webhookEvent()->toArray()])))
        ->toBeInstanceOf(ShouldBeUnique::class);
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
