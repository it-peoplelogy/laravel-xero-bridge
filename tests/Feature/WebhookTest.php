<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Events\XeroWebhookSignatureFailed;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;

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

it('fails closed when no webhook key is configured', function () {
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
    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('queue down'));

    $raw = eventsPayload();

    postWebhook($raw, signed($raw))->assertStatus(500);
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
