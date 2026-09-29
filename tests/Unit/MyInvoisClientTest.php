<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\MyInvois\IdType;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisException;

/**
 * LHDN MyInvois taxpayer TIN validation.
 *
 * Http::preventStrayRequests() is on in TestCase::setUp(), so any URL these
 * tests do not stub throws rather than quietly reaching hasil.gov.my.
 */
const SANDBOX = 'https://preprod-api.myinvois.hasil.gov.my';

beforeEach(function () {
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.environment', 'sandbox');
    config()->set('myinvois.client_id', 'test-myinvois-client-id');
    config()->set('myinvois.client_secret', 'test-myinvois-client-secret');
});

function myinvois(): MyInvoisClient
{
    return app(MyInvoisClient::class);
}

/** The token call plus whatever the validate call should answer. */
function fakeMyInvois(int $status = 200, array $body = [], array $headers = []): void
{
    Http::fake([
        SANDBOX.'/connect/token' => Http::response([
            'access_token' => 'lhdn-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'InvoicingAPI',
        ]),
        SANDBOX.'/api/v1.0/taxpayer/validate/*' => Http::response($body, $status, $headers),
    ]);
}

/*
|--------------------------------------------------------------------------
| The answer
|--------------------------------------------------------------------------
*/

it('answers true when HASiL holds the pair', function () {
    fakeMyInvois(200);

    expect(myinvois()->validate('C25845632020', IdType::BRN, '201901234567'))->toBeTrue();
});

it('answers false on a 404 rather than throwing', function () {
    // A 404 is the ANSWER "that TIN and ID combination cannot be found", not a
    // failure. Throwing it would force every caller to catch to learn "no".
    fakeMyInvois(404);

    expect(myinvois()->validate('C25845632020', IdType::BRN, '201901234567'))->toBeFalse();
});

it('calls the documented URL with both query parameters', function () {
    fakeMyInvois(200);

    myinvois()->validate('C25845632020', IdType::BRN, '201901234567');

    Http::assertSent(function (Request $r) {
        return str_starts_with($r->url(), SANDBOX.'/api/v1.0/taxpayer/validate/C25845632020')
            && str_contains($r->url(), 'idType=BRN')
            && str_contains($r->url(), 'idValue=201901234567')
            && $r->method() === 'GET';
    });
});

it('sends a letter-prefixed TIN through unaltered', function () {
    // LHDN publishes no TIN format and types the parameter as "Number" while
    // its own example is not one. Anything this package invented would
    // eventually reject a valid TIN.
    fakeMyInvois(200);

    myinvois()->validate('IG56003500070', 'NRIC', '770625015324');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/validate/IG56003500070'));
});

it('accepts an id type in any casing', function (string $given, string $sent) {
    fakeMyInvois(200);

    myinvois()->validate('C1', $given, 'x');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'idType='.$sent));
})->with([
    'lowercase' => ['brn', 'BRN'],
    'mixed' => ['Passport', 'PASSPORT'],
    'already canonical' => ['NRIC', 'NRIC'],
]);

it('refuses an id type outside the published list', function () {
    fakeMyInvois(200);

    expect(fn () => myinvois()->validate('C1', 'DRIVING_LICENCE', 'x'))
        ->toThrow(MyInvoisException::class, 'Unknown MyInvois idType');

    Http::assertNothingSent();
});

it('spends no request on a blank input', function (string $tin, string $idValue) {
    // The endpoint allows 60 requests per minute; a blank field can only ever
    // be a 400, so it is caught before it costs one.
    fakeMyInvois(200);

    expect(fn () => myinvois()->validate($tin, IdType::BRN, $idValue))
        ->toThrow(MyInvoisException::class);

    Http::assertNothingSent();
})->with([
    'blank tin' => ['', '201901234567'],
    'blank id value' => ['C25845632020', '   '],
]);

/*
|--------------------------------------------------------------------------
| Token caching -- the 12 RPM guard
|--------------------------------------------------------------------------
*/

it('acquires the access token once and reuses it', function () {
    // The token endpoint allows 12 requests per minute per Client ID, and LHDN
    // names one-token-per-call an anti-pattern. Without this, a loop over 50
    // taxpayers is blocked before the tenth.
    fakeMyInvois(200);

    $client = myinvois();

    foreach (range(1, 5) as $i) {
        $client->validate('C2584563202'.$i, IdType::BRN, '20190123456'.$i);
    }

    $tokenCalls = 0;

    Http::assertSent(function (Request $r) use (&$tokenCalls) {
        if (str_ends_with($r->url(), '/connect/token')) {
            $tokenCalls++;
        }

        return true;
    });

    expect($tokenCalls)->toBe(1);
});

it('sends the token as a bearer credential', function () {
    fakeMyInvois(200);

    myinvois()->validate('C25845632020', IdType::BRN, '201901234567');

    Http::assertSent(function (Request $r) {
        return str_contains($r->url(), '/validate/')
            && $r->hasHeader('Authorization', 'Bearer lhdn-access-token');
    });
});

it('requests the token with the documented grant and scope', function () {
    fakeMyInvois(200);

    myinvois()->validate('C25845632020', IdType::BRN, '201901234567');

    Http::assertSent(function (Request $r) {
        if (! str_ends_with($r->url(), '/connect/token')) {
            return true;
        }

        return $r->method() === 'POST'
            && $r['grant_type'] === 'client_credentials'
            && $r['scope'] === 'InvoicingAPI';
    });
});

it('re-acquires the token after it is forgotten', function () {
    fakeMyInvois(200);

    $client = myinvois();
    $client->validate('C1', IdType::BRN, 'x');
    $client->forgetToken();
    $client->validate('C2', IdType::BRN, 'y');

    $tokenCalls = 0;

    Http::assertSent(function (Request $r) use (&$tokenCalls) {
        if (str_ends_with($r->url(), '/connect/token')) {
            $tokenCalls++;
        }

        return true;
    });

    expect($tokenCalls)->toBe(2);
});

it('replays a 401 exactly once with a fresh token', function () {
    Http::fake([
        SANDBOX.'/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        SANDBOX.'/api/v1.0/taxpayer/validate/*' => Http::sequence()
            ->push('', 401)
            ->push('', 200),
    ]);

    expect(myinvois()->validate('C25845632020', IdType::BRN, '201901234567'))->toBeTrue();
});

it('gives up after a second 401 rather than looping', function () {
    // A freshly-minted token that is still rejected means the credentials are
    // not entitled to this API. Hammering LHDN is how a Client ID gets blocked.
    Http::fake([
        SANDBOX.'/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        SANDBOX.'/api/v1.0/taxpayer/validate/*' => Http::response('', 401),
    ]);

    expect(fn () => myinvois()->validate('C25845632020', IdType::BRN, '201901234567'))
        ->toThrow(MyInvoisException::class);
});

/*
|--------------------------------------------------------------------------
| Failures
|--------------------------------------------------------------------------
*/

it('reads the LHDN error envelope', function () {
    fakeMyInvois(400, [
        'error' => [
            'propertyName' => 'idType',
            'propertyPath' => 'taxpayer.idType',
            'errorCode' => 'BadArgument',
            'error' => 'Incorrect parameter provided',
            'errorMS' => 'Parameter yang diberikan tidak betul',
            'target' => 'idType',
            'innerError' => [['error' => 'idType must be one of NRIC, PASSPORT, BRN, ARMY']],
        ],
    ], ['correlationId' => 'abc-123']);

    try {
        myinvois()->validate('C25845632020', IdType::BRN, '201901234567');
        $this->fail('Expected MyInvoisException.');
    } catch (MyInvoisException $e) {
        expect($e->statusCode())->toBe(400)
            ->and($e->errorCode())->toBe('BadArgument')
            ->and($e->errorMalay())->toBe('Parameter yang diberikan tidak betul')
            ->and($e->propertyName())->toBe('idType')
            ->and($e->correlationId())->toBe('abc-123')
            ->and($e->innerErrors())->toContain('idType must be one of NRIC, PASSPORT, BRN, ARMY')
            // A 400 is our own bad request. Retrying sends the same one.
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('classifies a rate limit as retryable and reads Retry-After', function () {
    fakeMyInvois(429, [], ['Retry-After' => '30']);

    try {
        myinvois()->validate('C25845632020', IdType::BRN, '201901234567');
        $this->fail('Expected MyInvoisException.');
    } catch (MyInvoisException $e) {
        expect($e->isRetryable())->toBeTrue()
            ->and($e->retryAfter())->toBe(30)
            ->and($e->isConfigurationProblem())->toBeFalse();
    }
});

it('classifies a 403 as a provisioning problem, not something to retry', function () {
    fakeMyInvois(403);

    try {
        myinvois()->validate('C25845632020', IdType::BRN, '201901234567');
        $this->fail('Expected MyInvoisException.');
    } catch (MyInvoisException $e) {
        expect($e->isConfigurationProblem())->toBeTrue()
            ->and($e->isRetryable())->toBeFalse();
    }
});

it('names the credentials when the token endpoint rejects them', function () {
    Http::fake([
        SANDBOX.'/connect/token' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Client authentication failed',
        ], 400),
    ]);

    try {
        myinvois()->validate('C25845632020', IdType::BRN, '201901234567');
        $this->fail('Expected MyInvoisException.');
    } catch (MyInvoisException $e) {
        expect($e->getMessage())->toContain('MYINVOIS_CLIENT_ID')
            ->and($e->errorCode())->toBe('invalid_client')
            ->and($e->isConfigurationProblem())->toBeTrue();
    }
});

it('treats both spellings of unauthorised_client as fatal', function (string $spelling) {
    // LHDN documents the British spelling; RFC 6749 specifies the American one.
    Http::fake([
        SANDBOX.'/connect/token' => Http::response(['error' => $spelling], 400),
    ]);

    try {
        myinvois()->validate('C1', IdType::BRN, 'x');
        $this->fail('Expected MyInvoisException.');
    } catch (MyInvoisException $e) {
        expect($e->isConfigurationProblem())->toBeTrue();
    }
})->with(['unauthorised_client', 'unauthorized_client']);

/*
|--------------------------------------------------------------------------
| Secrets
|--------------------------------------------------------------------------
*/

it('never puts the client secret or the token in a failure', function (int $status) {
    fakeMyInvois($status, ['error' => ['error' => 'something went wrong']]);

    try {
        myinvois()->validate('C25845632020', IdType::BRN, '201901234567');
    } catch (MyInvoisException $e) {
        $exposed = $e->getMessage().json_encode($e->context()).$e->getTraceAsString();

        expect($exposed)->not->toContain('test-myinvois-client-secret')
            ->not->toContain('lhdn-access-token');
    }
})->with([400, 403, 429, 500]);

/*
|--------------------------------------------------------------------------
| Rate limit reporting
|--------------------------------------------------------------------------
*/

it('reports the rate limit LHDN returned', function () {
    fakeMyInvois(200, [], [
        'X-Rate-Limit-Limit' => '60',
        'X-Rate-Limit-Remaining' => '59',
        'X-Rate-Limit-Reset' => '2026-09-29T12:23:41Z',
    ]);

    $client = myinvois();
    $client->validate('C25845632020', IdType::BRN, '201901234567');

    // minute_remaining matches the Xero side's key name, so the console's
    // response bar renders it with no JavaScript change.
    expect($client->lastRateLimit())
        ->toMatchArray(['minute_remaining' => 59, 'limit' => 60]);
});

it('reports no rate limit before anything has been called', function () {
    expect(myinvois()->lastRateLimit())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Result cache
|--------------------------------------------------------------------------
*/

it('does not cache a result by default', function () {
    fakeMyInvois(200);

    $client = myinvois();
    $client->validate('C25845632020', IdType::BRN, '201901234567');
    $client->validate('C25845632020', IdType::BRN, '201901234567');

    $validateCalls = 0;

    Http::assertSent(function (Request $r) use (&$validateCalls) {
        if (str_contains($r->url(), '/validate/')) {
            $validateCalls++;
        }

        return true;
    });

    expect($validateCalls)->toBe(2);
});

it('caches a positive answer when a matched ttl is set', function () {
    config()->set('myinvois.results.matched_ttl', 3600);

    fakeMyInvois(200);

    $client = myinvois();
    expect($client->validate('C25845632020', IdType::BRN, '201901234567'))->toBeTrue();
    expect($client->validate('C25845632020', IdType::BRN, '201901234567'))->toBeTrue();

    $validateCalls = 0;

    Http::assertSent(function (Request $r) use (&$validateCalls) {
        if (str_contains($r->url(), '/validate/')) {
            $validateCalls++;
        }

        return true;
    });

    expect($validateCalls)->toBe(1);
});

it('does not cache a negative answer just because positives are cached', function () {
    // Asymmetric on purpose: a "yes" is a durable fact, a "no" is usually a
    // typo the user is about to correct.
    config()->set('myinvois.results.matched_ttl', 3600);
    config()->set('myinvois.results.unmatched_ttl', 0);

    fakeMyInvois(404);

    $client = myinvois();
    expect($client->validate('C1', IdType::BRN, 'x'))->toBeFalse();
    expect($client->validate('C1', IdType::BRN, 'x'))->toBeFalse();

    $validateCalls = 0;

    Http::assertSent(function (Request $r) use (&$validateCalls) {
        if (str_contains($r->url(), '/validate/')) {
            $validateCalls++;
        }

        return true;
    });

    expect($validateCalls)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The disabled default
|--------------------------------------------------------------------------
*/

it('refuses to do anything while disabled, naming the key', function () {
    config()->set('myinvois.enabled', false);

    expect(fn () => myinvois()->validate('C1', IdType::BRN, 'x'))
        ->toThrow(MyInvoisException::class, 'MYINVOIS_ENABLED');

    Http::assertNothingSent();
});

it('resolves from the container even while disabled', function () {
    // Binding it unconditionally is what makes "disabled" a one-line message
    // rather than a BindingResolutionException from inside the container.
    config()->set('myinvois.enabled', false);

    expect(myinvois())->toBeInstanceOf(MyInvoisClient::class);
});
