<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Capture\ApiCallRecorder;
use Peoplelogy\XeroBridge\Models\XeroApiCall;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * The capture table: what earns a row, and what never reaches one.
 */
function capture(array $overrides = []): void
{
    config()->set('xero-bridge.capture.enabled', true);

    foreach ($overrides as $key => $value) {
        config()->set('xero-bridge.capture.'.$key, $value);
    }

    app(TableGuard::class)->flush();
}

function recorder(): ApiCallRecorder
{
    return app(ApiCallRecorder::class);
}

/**
 * A real Illuminate response, so the recorder sees what it will see live.
 *
 * Each call gets its OWN path, because Http::fake() APPENDS stubs rather than
 * replacing them -- reuse one path and the first stub answers every later call,
 * which silently turns a two-outcome test into a one-outcome test.
 */
function fakeResponse(array|string $body, int $status = 200, string $type = 'application/json')
{
    static $n = 0;

    $path = 'r'.(++$n);

    Http::fake(['capture.test/'.$path => Http::response($body, $status, ['Content-Type' => $type])]);

    return Http::get('https://capture.test/'.$path);
}

/*
|--------------------------------------------------------------------------
| Nothing happens until it is switched on
|--------------------------------------------------------------------------
*/

it('records nothing while capture is off', function () {
    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices');

    expect(XeroApiCall::count())->toBe(0);
});

it('records nothing when the table was never migrated', function () {
    capture(['table' => 'not_a_real_table']);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices');

    // No row, and more importantly no exception: the call it was recording
    // must be unaffected.
    expect(true)->toBeTrue();
});

it('records nothing on a channel that was switched off', function () {
    capture(['channels.myinvois.api' => false]);

    recorder()->record('myinvois.api', 'GET', 'https://preprod-api.myinvois.hasil.gov.my/x');
    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices');

    expect(XeroApiCall::count())->toBe(1)
        ->and(XeroApiCall::first()->channel)->toBe('xero.api');
});

/*
|--------------------------------------------------------------------------
| Which calls earn a row
|--------------------------------------------------------------------------
*/

it('skips a successful read in the default writes mode', function () {
    capture();

    recorder()->record('xero.api', 'GET', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Invoices' => []]));

    expect(XeroApiCall::count())->toBe(0);
});

it('records a read that FAILED, even in writes mode', function () {
    capture();

    recorder()->record('xero.api', 'GET', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Message' => 'nope'], 403));

    expect(XeroApiCall::count())->toBe(1)
        ->and(XeroApiCall::first()->status)->toBe(403);
});

it('records a rejected batch that Xero answered with a 200', function () {
    capture();

    // createMany sends summarizeErrors=false, so a rejection arrives as a 200.
    // A rule keyed only on the status would file this as ordinary read traffic.
    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Invoices' => [['StatusAttributeString' => 'ERROR']]]));

    expect(XeroApiCall::count())->toBe(1);
});

it('records everything in all mode', function () {
    capture(['mode' => 'all']);

    recorder()->record('xero.api', 'GET', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Invoices' => []]));

    expect(XeroApiCall::count())->toBe(1);
});

it('records only failures in errors mode', function () {
    capture(['mode' => 'errors']);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Invoices' => []]));

    expect(XeroApiCall::count())->toBe(0);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        error: new RuntimeException('connection timed out'));

    expect(XeroApiCall::count())->toBe(1);
});

it('skips an endpoint whose only field is a capability link', function () {
    capture(['mode' => 'all']);

    recorder()->record('xero.api', 'GET', 'https://api.xero.com/api.xro/2.0/Invoices/abc/OnlineInvoice',
        response: fakeResponse(['OnlineInvoices' => [['OnlineInvoiceUrl' => 'https://in.xero.com/x']]]));

    expect(XeroApiCall::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What reaches the row
|--------------------------------------------------------------------------
*/

it('stores the call with its bank details already removed', function () {
    capture(['mode' => 'all']);

    recorder()->record(
        channel: 'xero.api',
        method: 'GET',
        url: 'https://api.xero.com/api.xro/2.0/Accounts',
        requestHeaders: ['Authorization' => 'Bearer live-token-value', 'Xero-tenant-id' => 'tenant-1'],
        response: fakeResponse(['Accounts' => [[
            'Code' => '090',
            'Type' => 'BANK',
            'BankAccountNumber' => '0209087654321050',
        ]]]),
        startedAt: hrtime(true),
        connectionKey: 'default',
    );

    $row = XeroApiCall::sole();

    expect($row->response_body['Accounts'][0]['BankAccountNumber'])->toBe('[redacted]')
        ->and($row->response_body['Accounts'][0]['Code'])->toBe('090')
        // The credential never reaches the row either.
        ->and($row->request_headers['Authorization'])->toBe('[redacted]')
        // But the organisation does, or the row cannot be attributed.
        ->and($row->request_headers['Xero-tenant-id'])->toBe('tenant-1')
        ->and($row->tenant_id)->toBe('tenant-1')
        ->and($row->connection_key)->toBe('default')
        ->and($row->redacted_keys)->toContain('bankaccountnumber')
        ->and($row->duration_ms)->toBeGreaterThanOrEqual(0);

    // Nothing sensitive anywhere in the serialised row.
    expect(json_encode($row->toArray()))
        ->not->toContain('0209087654321050')
        ->not->toContain('live-token-value');
});

it('lifts the idempotency key and correlation id into their own columns', function () {
    capture(['mode' => 'all']);

    Http::fake(['capture.test/*' => Http::response(['ok' => true], 200, [
        'Content-Type' => 'application/json',
        'correlationId' => 'corr-9',
    ])]);

    recorder()->record(
        channel: 'xero.api',
        method: 'POST',
        url: 'https://api.xero.com/api.xro/2.0/Invoices',
        requestHeaders: ['Idempotency-Key' => 'xb_abc123'],
        response: Http::get('https://capture.test/thing'),
    );

    $row = XeroApiCall::sole();

    // The join to xero_write_records, and the id LHDN support asks for.
    expect($row->idempotency_key)->toBe('xb_abc123')
        ->and($row->correlation_id)->toBe('corr-9');
});

it('keeps the size of a non-JSON body but not the bytes', function () {
    capture(['mode' => 'all']);

    recorder()->record('xero.api', 'GET', 'https://api.xero.com/api.xro/2.0/Invoices/abc',
        response: fakeResponse('%PDF-1.4 ... binary ...', 200, 'application/pdf'));

    $row = XeroApiCall::sole();

    // The rendered invoice carries the organisation's own bank details out of
    // the branding theme, and no key rule can reach inside it.
    expect($row->response_body)->toBeNull()
        ->and($row->content_type)->toBe('application/pdf')
        ->and($row->response_bytes)->toBeGreaterThan(0)
        ->and($row->bodyWasDropped())->toBeTrue();
});

it('replaces an oversized body with a marker rather than half a document', function () {
    capture(['mode' => 'all', 'max_body_bytes' => 1024]);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Invoices' => array_fill(0, 200, ['Reference' => str_repeat('x', 40)])]));

    $row = XeroApiCall::sole();

    expect($row->response_body['_truncated'])->toBeTrue()
        ->and($row->response_body['_keys'])->toBe(['Invoices'])
        ->and($row->response_body['_bytes'])->toBeGreaterThan(1024);
});

it('records a call that never got an answer', function () {
    capture(['mode' => 'all']);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        error: new RuntimeException('cURL error 28: timed out'));

    $row = XeroApiCall::sole();

    // The single most interesting row in the table: we sent something and
    // never learned the outcome.
    expect($row->status)->toBeNull()
        ->and($row->error)->toContain('timed out')
        ->and(XeroApiCall::failed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The joins a dashboard is built on
|--------------------------------------------------------------------------
*/

it('attributes calls to one of the host own records', function () {
    capture(['mode' => 'all']);

    $owner = XeroConnection::query()->firstOrCreate(
        ['key' => 'default'],
        [
            'tenant_id' => 't-1',
            'tenant_name' => 'Demo',
            'access_token' => 'a',
            'refresh_token' => 'r',
            'scopes' => 'x',
            'expires_at' => now()->addHour(),
        ],
    );

    recorder()->forOwner($owner, function () {
        recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices');
    });

    // And the owner does not leak into the next call on a long-lived worker.
    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Contacts');

    expect(XeroApiCall::forOwner($owner)->count())->toBe(1)
        ->and(XeroApiCall::whereNull('owner_id')->count())->toBe(1);
});

it('groups the attempts of one logical call', function () {
    capture(['mode' => 'all']);

    $id = recorder()->newCallId();

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Message' => 'expired'], 401), logicalCallId: $id, attempt: 1);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices',
        response: fakeResponse(['Invoices' => []]), logicalCallId: $id, attempt: 2);

    $attempts = XeroApiCall::logicalCall($id)->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->status)->toBe(401)
        ->and($attempts[1]->status)->toBe(200);
});

it('prunes rows past the retention window', function () {
    capture(['mode' => 'all', 'retain_days' => 90]);

    recorder()->record('xero.api', 'POST', 'https://api.xero.com/api.xro/2.0/Invoices');

    XeroApiCall::query()->update(['created_at' => now()->subDays(91)]);

    expect((new XeroApiCall)->prunable()->count())->toBe(1);
});
