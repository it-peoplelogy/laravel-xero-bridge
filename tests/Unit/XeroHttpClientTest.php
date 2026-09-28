<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Peoplelogy\XeroBridge\Exceptions\XeroAuthenticationException;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroRequestException;
use Peoplelogy\XeroBridge\Exceptions\XeroScopeException;
use Peoplelogy\XeroBridge\Exceptions\XeroServiceUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

beforeEach(function () {
    connection();
    // Backoffs are asserted, never actually waited on.
    Sleep::fake();
});

it('sends the tenant header, bearer token and JSON accept header', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []], 200, xeroHeaders())]);

    XeroBridge::request('GET', 'Invoices');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.xero.com/api.xro/2.0/Invoices'
        && $r->hasHeader('Authorization', 'Bearer access-token-1')
        && $r->hasHeader('Xero-tenant-id', 'tenant-1')
        && $r->hasHeader('Accept', 'application/json'));
});

it('does not let a caller override the Accept header', function () {
    // Without Accept: application/json Xero replies with XML and every
    // ->json() call silently returns null.
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []])]);

    XeroBridge::request('GET', 'Invoices', [], ['Accept' => 'application/xml']);

    Http::assertSent(fn (Request $r) => $r->header('Accept') === ['application/json']);
});

it('does not let a caller override the tenant header', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []])]);

    XeroBridge::request('GET', 'Invoices', [], ['Xero-tenant-id' => 'somebody-elses-tenant']);

    Http::assertSent(fn (Request $r) => $r->header('Xero-tenant-id') === ['tenant-1']);
});

it('treats 204 No Content as success', function () {
    // POST /Invoices/{id}/Email returns 204 with an empty body; calling
    // ->json() on it would turn a successful send into a failure.
    Http::fake(['api.xero.com/*' => Http::response('', 204)]);

    expect(XeroBridge::request('POST', 'Invoices/abc/Email'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Retries
|--------------------------------------------------------------------------
*/

it('honours Retry-After on a 429', function () {
    Http::fake([
        'api.xero.com/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '3', 'X-Rate-Limit-Problem' => 'minute'])
            ->push(['Invoices' => []], 200),
    ]);

    XeroBridge::request('GET', 'Invoices');

    Http::assertSentCount(2);
    // totalMilliseconds is a float, so compare as an int.
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 3000);
});

it('backs off briefly on a concurrency 429, which carries no Retry-After', function () {
    Http::fake([
        'api.xero.com/*' => Http::sequence()
            ->push('', 429, ['X-Rate-Limit-Problem' => 'concurrent'])
            ->push(['Invoices' => []], 200),
    ]);

    XeroBridge::request('GET', 'Invoices');

    Http::assertSentCount(2);
    // The concurrency limit frees in milliseconds; waiting a minute would be
    // absurd, so the fallback must stay short.
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds <= 2000);
});

it('refuses to sleep through a daily limit and surfaces it instead', function () {
    Http::fake([
        'api.xero.com/*' => Http::response('', 429, [
            'Retry-After' => '86400',
            'X-Rate-Limit-Problem' => 'day',
        ]),
    ]);

    // Blocking a worker for 24 hours is not an option; the job should
    // release() itself using retryAfter().
    try {
        XeroBridge::request('GET', 'Invoices');
        $this->fail('Expected XeroRateLimitException.');
    } catch (XeroRateLimitException $e) {
        expect($e->retryAfter())->toBe(86400)
            ->and($e->limitProblem())->toBe('day');
    }

    Http::assertSentCount(1);
});

it('retries a 500 and gives up with an exception', function () {
    Http::fake(['api.xero.com/*' => Http::response('', 500)]);

    expect(fn () => XeroBridge::request('GET', 'Invoices'))
        ->toThrow(XeroServiceUnavailableException::class);

    // http.retries is TOTAL attempts, not additional ones.
    Http::assertSentCount(3);
});

it('does not retry an organisation-offline 503', function () {
    Http::fake(['api.xero.com/*' => Http::response('The Organisation is offline', 503)]);

    try {
        XeroBridge::request('GET', 'Invoices');
        $this->fail('Expected XeroServiceUnavailableException.');
    } catch (XeroServiceUnavailableException $e) {
        // Xero suggests waiting about 5 minutes -- not an inline wait.
        expect($e->retryAfter())->toBe(300);
    }

    Http::assertSentCount(1);
});

it('never retries a write without an idempotency key', function () {
    config()->set('xero-bridge.http.idempotency', false);
    Http::fake(['api.xero.com/*' => Http::response('', 500)]);

    expect(fn () => XeroBridge::request('POST', 'Invoices', ['Type' => 'ACCREC']))
        ->toThrow(XeroServiceUnavailableException::class);

    // A retried POST that actually succeeded creates a duplicate invoice.
    Http::assertSentCount(1);
});

it('reuses one idempotency key across the retries of a single call', function () {
    Http::fake([
        'api.xero.com/*' => Http::sequence()
            ->push('', 500)
            ->push(['Invoices' => []], 200),
    ]);

    XeroBridge::request('POST', 'Invoices', ['Type' => 'ACCREC']);

    $keys = [];
    Http::assertSent(function (Request $r) use (&$keys) {
        $keys[] = $r->header('Idempotency-Key')[0] ?? null;

        return true;
    });

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and(strlen((string) $keys[0]))->toBeLessThanOrEqual(128);
});

/*
|--------------------------------------------------------------------------
| The 401 path
|--------------------------------------------------------------------------
*/

it('refreshes once and replays after a 401', function () {
    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'expires_in' => 1800,
            'scope' => 'offline_access',
        ]),
        'api.xero.com/api.xro/2.0/*' => Http::sequence()
            ->push(['Title' => 'Unauthorized', 'Status' => 401, 'Detail' => 'TokenExpired'], 401)
            ->push(['Invoices' => []], 200),
    ]);

    XeroBridge::request('GET', 'Invoices');

    // API, token, API -- exactly one refresh and one replay.
    Http::assertSentCount(3);
});

it('gives up after a second 401 rather than looping', function () {
    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'expires_in' => 1800,
        ]),
        'api.xero.com/api.xro/2.0/*' => Http::response(['Title' => 'Unauthorized', 'Status' => 401], 401),
    ]);

    expect(fn () => XeroBridge::request('GET', 'Invoices'))
        ->toThrow(XeroAuthenticationException::class);

    // The token endpoint was hit exactly once.
    Http::assertSentCount(3);
});

it('never refreshes on an insufficient-scope 401', function (string $header) {
    Http::fake([
        'api.xero.com/*' => Http::response('', 401, ['WWW-Authenticate' => $header]),
    ]);

    // Refreshing cannot fix a missing scope; retrying it loops forever.
    expect(fn () => XeroBridge::request('GET', 'Invoices'))
        ->toThrow(XeroScopeException::class, '/xero/connect/default');

    Http::assertSentCount(1);
})->with([
    // Xero's documented spelling is the typo, but handle both.
    'Xero typo' => 'Bearer error="insufficent_scope"',
    'correct spelling' => 'Bearer error="insufficient_scope"',
]);

/*
|--------------------------------------------------------------------------
| Rate-limit telemetry
|--------------------------------------------------------------------------
*/

it('records the rate-limit headers from every response', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []], 200, xeroHeaders([
        'X-MinLimit-Remaining' => '42',
        'X-DayLimit-Remaining' => '1234',
    ]))]);

    XeroBridge::request('GET', 'Invoices');

    $status = XeroBridge::client()->lastRateLimit();

    expect($status->minuteRemaining)->toBe(42)
        ->and($status->dayRemaining)->toBe(1234)
        ->and($status->isRunningLow())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Query encoding
|--------------------------------------------------------------------------
*/

it('encodes a where clause exactly once', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []])]);

    XeroBridge::client()->get('Invoices', ['where' => 'Status=="AUTHORISED"']);

    // Double-encoding puts a literal % into Xero's filter parser and the
    // request fails.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'where=Status%3D%3D%22AUTHORISED%22')
        && ! str_contains($r->url(), '%253D'));
});

it('sends booleans as true/false, not 1/0', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []])]);

    XeroBridge::client()->get('Invoices', ['summaryOnly' => true]);

    // PHP renders true as "1", which Xero silently ignores.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'summaryOnly=true')
        && ! str_contains($r->url(), 'summaryOnly=1'));
});

/*
|--------------------------------------------------------------------------
| Errors
|--------------------------------------------------------------------------
*/

it('parses validation errors out of a 400', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'ErrorNumber' => 10,
        'Type' => 'ValidationException',
        'Message' => 'A validation exception occurred',
        'Elements' => [[
            'ValidationErrors' => [['Message' => 'Email address must be valid']],
        ]],
    ], 400)]);

    try {
        XeroBridge::request('POST', 'Invoices', []);
        $this->fail('Expected XeroValidationException.');
    } catch (XeroValidationException $e) {
        expect($e->validationErrors())->toBe(['Email address must be valid'])
            ->and($e->xeroErrorNumber())->toBe(10)
            ->and($e->xeroType())->toBe('ValidationException')
            // The detail must survive into the message: Laravel truncates
            // RequestException messages at 120 characters, which would
            // otherwise hide exactly this.
            ->and($e->getMessage())->toContain('Email address must be valid');
    }
});

it('reads Description as well as Message in validation errors', function () {
    // Xero's own docs use Message in one bulk example and Description in
    // another, for the same feature.
    Http::fake(['api.xero.com/*' => Http::response([
        'Elements' => [['ValidationErrors' => [['Description' => 'Account code 999 is invalid']]]],
    ], 400)]);

    expect(fn () => XeroBridge::request('POST', 'Invoices', []))
        ->toThrow(XeroValidationException::class, 'Account code 999 is invalid');
});

it('parses the PascalCase problem envelope used for 403', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Type' => null,
        'Title' => 'Forbidden',
        'Status' => 403,
        'Detail' => 'AuthenticationUnsuccessful',
        'Instance' => 'abc',
    ], 403)]);

    expect(fn () => XeroBridge::request('GET', 'Invoices'))
        ->toThrow(XeroAuthenticationException::class, 'AuthenticationUnsuccessful');
});

it('explains an XML body as a missing Accept header', function () {
    Http::fake(['api.xero.com/*' => Http::response('<?xml version="1.0"?><Response/>', 400)]);

    expect(fn () => XeroBridge::request('GET', 'Invoices'))
        ->toThrow(XeroRequestException::class, 'Accept');
});

it('never leaks a token or the client secret into an exception', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Elements' => [['ValidationErrors' => [['Message' => 'nope']]]],
    ], 400)]);

    try {
        XeroBridge::request('POST', 'Invoices', []);
    } catch (Throwable $e) {
        $serialised = $e->getMessage().json_encode($e->context());

        expect($serialised)->not->toContain('access-token-1')
            ->not->toContain('refresh-token-1')
            ->not->toContain('test-client-secret');
    }
});
