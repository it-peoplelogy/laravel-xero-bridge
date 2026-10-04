<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Events\XeroWebhookSignatureFailed;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;

/**
 * A listener a host might queue so its alerting cannot slow the 401 down --
 * which moves the failure to the push, while the queue is down.
 */
final class QueuedSignatureFailureListener implements ShouldQueue
{
    public function handle(XeroWebhookSignatureFailed $event): void {}
}

/**
 * Posts RAW bytes. postJson() would re-encode the body, which is exactly
 * what the signature check must reject -- so using it here would quietly
 * defeat the point of these tests.
 */
function postWebhook(string $raw, ?string $signature): TestResponse
{
    return test()->call(
        'POST',
        '/xero/webhook',
        [],
        [],
        [],
        array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XERO_SIGNATURE' => $signature,
        ]),
        $raw,
    );
}

function signed(string $raw): string
{
    return WebhookSignature::compute($raw, 'test-webhook-key');
}

function eventsPayload(int $count = 2): string
{
    $events = [];

    for ($i = 1; $i <= $count; $i++) {
        $events[] = [
            'resourceUrl' => "https://api.xero.com/api.xro/2.0/Invoices/inv-{$i}",
            'resourceId' => "inv-{$i}",
            'eventDateUtc' => '2026-09-28T01:15:39.902',
            'eventType' => 'UPDATE',
            'eventCategory' => 'INVOICE',
            'tenantId' => 'tenant-1',
            'tenantType' => 'ORGANISATION',
        ];
    }

    return json_encode([
        'events' => $events,
        'firstEventSequence' => 1,
        'lastEventSequence' => $count,
        'entropy' => 'S0m3r4Nd0mt3xt',
    ]);
}

it('registers the webhook route', function () {
    expect(Route::has('xero-bridge.webhook'))->toBeTrue();
});

it('accepts a correctly signed payload', function () {
    Queue::fake();
    $raw = eventsPayload();

    postWebhook($raw, signed($raw))->assertOk();

    Queue::assertPushed(ProcessXeroWebhook::class, 1);
});

it('rejects a bad signature with 401', function () {
    Queue::fake();
    Event::fake([XeroWebhookSignatureFailed::class]);
    $raw = eventsPayload();

    postWebhook($raw, 'not-the-signature')->assertUnauthorized();

    Queue::assertNothingPushed();
    Event::assertDispatched(XeroWebhookSignatureFailed::class);
});

it('rejects a missing signature header with 401', function () {
    Queue::fake();

    postWebhook(eventsPayload(), null)->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('fails closed when the key is gone but a stale route:cache still serves the route', function () {
    // Booted without a key there is no route at all (tests/WebhookKeyUnset).
    // A route:cache built while a key was set keeps the route after the key
    // is removed, so the controller still has to refuse what it cannot
    // verify. Nulled after boot, which leaves the route exactly so.
    config()->set('xero-bridge.webhook_key', null);
    $raw = eventsPayload();

    // A 500 here would be worse than a 401: it counts against the
    // subscription during intent-to-receive.
    postWebhook($raw, signed($raw))->assertUnauthorized();
});

it('hashes the raw bytes, not a re-encoded body', function () {
    Queue::fake();

    $signedBody = '{"events":[],"entropy":"x"}';
    // Same JSON semantically, one byte different.
    $sentBody = '{"events": [],"entropy":"x"}';

    postWebhook($sentBody, signed($signedBody))->assertUnauthorized();
});

it('rejects a hex-encoded signature', function () {
    // hash_hmac(..., false) then base64 is a classic mistake; it must not
    // pass.
    $raw = eventsPayload();
    $hex = base64_encode(hash_hmac('sha256', $raw, 'test-webhook-key', false));

    postWebhook($raw, $hex)->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Intent to receive
|--------------------------------------------------------------------------
*/

/**
 * Xero's intent-to-receive probe: an EMPTY events array. Xero sends it both
 * correctly and incorrectly signed and requires 200 and 401 respectively.
 */
function intentToReceiveBody(): string
{
    return (string) json_encode([
        'events' => [],
        'lastEventSequence' => 0,
        'firstEventSequence' => 0,
        'entropy' => 'S0m3r4Nd0mt3xt',
    ]);
}

it('answers the intent-to-receive ping with 200 and queues nothing', function () {
    Queue::fake();
    Event::fake([XeroWebhookReceived::class]);

    // Xero's validation payload has an EMPTY events array.
    $raw = json_encode([
        'events' => [],
        'lastEventSequence' => 0,
        'firstEventSequence' => 0,
        'entropy' => 'S0m3r4Nd0mt3xt',
    ]);

    postWebhook($raw, signed($raw))->assertOk();

    Queue::assertNothingPushed();
    Event::assertNotDispatched(XeroWebhookReceived::class);
});

it('answers the intent-to-receive ping with 200 even when the queue is down', function () {
    // The ping never reaches the queue, so a dead queue cannot fail the
    // correctly signed half of Xero's check.
    $this->mock(Dispatcher::class)->shouldNotReceive('dispatch');

    $raw = intentToReceiveBody();

    postWebhook($raw, signed($raw))->assertOk();
});

it('still answers 401 when a signature-failure listener throws', function (string $raw) {
    // The other half of Xero's check: the mis-signed probes need a 401, and a
    // 500 fails the check as surely as a 200 would. A counter kept in a cache
    // that is down is the realistic way a listener throws here.
    Queue::fake();
    Log::spy();
    Event::listen(XeroWebhookSignatureFailed::class, fn () => throw new RuntimeException('cache store down'));

    postWebhook($raw, 'not-the-signature')->assertUnauthorized();

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context = []): bool => $message === 'xero-bridge: a XeroWebhookSignatureFailed listener failed; answered 401 regardless.'
            && $context['exception'] === 'cache store down'
            && $context['exception_class'] === RuntimeException::class,
    );
})->with([
    'a delivery with events' => [eventsPayload()],
    'the intent-to-receive probe' => [intentToReceiveBody()],
]);

it('still answers 401 when a queued signature-failure listener cannot be queued', function () {
    // A queued listener is pushed DURING the request, so a queue that is down
    // throws here. The jobs table is missing, which is what a dead database
    // queue looks like from inside the request.
    config()->set('queue.connections.webhook_queue_down', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'no_such_jobs_table',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('queue.default', 'webhook_queue_down');

    Event::listen(XeroWebhookSignatureFailed::class, QueuedSignatureFailureListener::class);

    postWebhook(intentToReceiveBody(), 'not-the-signature')->assertUnauthorized();
});

it('returns no cookies at all', function () {
    Queue::fake();
    $raw = eventsPayload();

    $response = postWebhook($raw, signed($raw));

    // ANY cookie fails Xero's intent-to-receive check.
    $response->assertHeaderMissing('Set-Cookie');
    expect($response->baseResponse->headers->getCookies())->toBeEmpty();
});

it('strips a cookie queued by the application', function () {
    Queue::fake();

    // Not hypothetical: Sanctum's stateful middleware starts a session, and
    // a global middleware can queue a cookie. Excluding the `web` group is
    // necessary but not sufficient -- removing the header is the guarantee.
    Cookie::queue('some_app_cookie', 'value');

    $raw = eventsPayload();
    $response = postWebhook($raw, signed($raw));

    $response->assertOk()->assertHeaderMissing('Set-Cookie');
    expect($response->baseResponse->headers->getCookies())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Dispatch
|--------------------------------------------------------------------------
*/

it('never runs listeners during the request', function () {
    Queue::fake();
    Event::fake([XeroWebhookReceived::class]);
    $raw = eventsPayload();

    postWebhook($raw, signed($raw))->assertOk();

    // One envelope job carrying both events -- and no event dispatched
    // inline, so a slow listener cannot blow the 5-second budget.
    Queue::assertPushed(ProcessXeroWebhook::class, 1);
    Event::assertNotDispatched(XeroWebhookReceived::class);
});

it('fires one event per item, in order, when the job runs', function () {
    Event::fake([XeroWebhookReceived::class]);

    (new ProcessXeroWebhook(json_decode(eventsPayload(3), true)))->handle();

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 3);

    $seen = [];
    Event::assertDispatched(XeroWebhookReceived::class, function (XeroWebhookReceived $e) use (&$seen) {
        $seen[] = $e->resourceId();

        return true;
    });

    expect($seen)->toBe(['inv-1', 'inv-2', 'inv-3']);
});

it('returns 500 when the job cannot be queued so Xero retries', function () {
    Log::spy();

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('queue down'));

    $raw = eventsPayload();

    postWebhook($raw, signed($raw))->assertStatus(500);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context = []): bool => $message === 'xero-bridge: could not queue a Xero webhook, '
            .'or (on the sync driver) a listener failed while processing it.'
            && $context['exception'] === 'queue down'
            && $context['exception_class'] === RuntimeException::class,
    );
});

it('returns 500 when a listener throws on the sync driver, so Xero retries', function () {
    // No Queue::fake(): the suite's queue is sync, so the job -- and every
    // listener -- runs inside the request, and a listener's exception is the
    // response. A 500 is the right answer, since Xero then retries.
    Event::listen(XeroWebhookReceived::class, fn () => throw new RuntimeException('listener failed'));
    Log::spy();

    $raw = eventsPayload(1);

    postWebhook($raw, signed($raw))->assertStatus(500);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'on the sync driver')
            && $context['exception'] === 'listener failed'
            && $context['exception_class'] === RuntimeException::class,
    );
});

it('returns 200 for a signed body that is not JSON', function () {
    Queue::fake();

    // It is genuinely from Xero, so anything but a 2xx counts against the
    // subscription.
    postWebhook('not json', signed('not json'))->assertOk();

    Queue::assertNothingPushed();
});

it('uses the configured queue', function () {
    Queue::fake();
    config()->set('xero-bridge.webhooks.queue', 'xero');
    $raw = eventsPayload();

    postWebhook($raw, signed($raw))->assertOk();

    Queue::assertPushedOn('xero', ProcessXeroWebhook::class);
});

/*
|--------------------------------------------------------------------------
| Payload shape
|--------------------------------------------------------------------------
*/

it('compares eventType case-insensitively', function (string $type) {
    Event::fake([XeroWebhookReceived::class]);

    // Xero's OpenAPI spec declares UPDATE; its own docs show "Update".
    $payload = json_decode(eventsPayload(1), true);
    $payload['events'][0]['eventType'] = $type;

    (new ProcessXeroWebhook($payload))->handle();

    Event::assertDispatched(XeroWebhookReceived::class, fn ($e) => $e->event->isUpdate());
})->with(['UPDATE', 'Update', 'update']);

it('keeps an unfamiliar event category rather than failing', function () {
    Event::fake([XeroWebhookReceived::class]);

    $payload = json_decode(eventsPayload(1), true);
    $payload['events'][0]['eventCategory'] = 'PURCHASEORDER';

    (new ProcessXeroWebhook($payload))->handle();

    Event::assertDispatched(
        XeroWebhookReceived::class,
        fn ($e) => $e->event->eventCategory === 'PURCHASEORDER'
    );
});

it('tolerates a missing tenantType', function () {
    Event::fake([XeroWebhookReceived::class]);

    $payload = json_decode(eventsPayload(1), true);
    unset($payload['events'][0]['tenantType']);

    (new ProcessXeroWebhook($payload))->handle();

    Event::assertDispatched(
        XeroWebhookReceived::class,
        fn ($e) => $e->event->tenantType === 'ORGANISATION'
    );
});

it('exposes the envelope sequence fields', function () {
    Event::fake([XeroWebhookReceived::class]);

    (new ProcessXeroWebhook(json_decode(eventsPayload(2), true)))->handle();

    Event::assertDispatched(XeroWebhookReceived::class, fn ($e) => $e->envelope->firstEventSequence === 1
        && $e->envelope->lastEventSequence === 2
        && $e->envelope->entropy === 'S0m3r4Nd0mt3xt');
});
