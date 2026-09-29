<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Models\XeroApiCall;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * Proof that capture is actually WIRED, not merely implemented.
 *
 * The recorder is covered on its own elsewhere. These tests drive the real
 * clients end to end, because a redactor nothing calls protects nothing and a
 * call site that was never wired is invisible until someone goes looking for a
 * row that was never written.
 */
beforeEach(function () {
    connection();

    config()->set('xero-bridge.capture.enabled', true);
    config()->set('xero-bridge.capture.mode', 'all');

    app(TableGuard::class)->flush();
});

it('captures a real Xero write through the client, already redacted', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [[
            'InvoiceID' => 'inv-1',
            'InvoiceNumber' => 'INV-0042',
            'Status' => 'DRAFT',
            // Xero echoes the whole contact back, bank details included.
            'Contact' => ['Name' => 'Acme', 'BankAccountDetails' => '12334567', 'TaxNumber' => 'C25845632020'],
        ]],
    ], 200, xeroHeaders())]);

    XeroBridge::connection('default')->invoices()->create([
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => 'c-1'],
        'LineItems' => [['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 100, 'AccountCode' => '200']],
    ]);

    $row = XeroApiCall::where('channel', 'xero.api')->sole();

    expect($row->method)->toBe('POST')
        ->and($row->status)->toBe(200)
        ->and($row->connection_key)->toBe('default')
        // The idempotency key reached its own column, which is the join to
        // xero_write_records and the only way to tell a retry from a duplicate.
        ->and($row->idempotency_key)->toStartWith('xb_')
        // What we sent survives, because it is the point of the row.
        ->and($row->request_body['Invoices'][0]['LineItems'][0]['AccountCode'])->toBe('200');

    // The bearer token was genuinely present on the request and did not land.
    expect($row->request_headers['Authorization'])->toBe('[redacted]');

    $contact = $row->response_body['Invoices'][0]['Contact'];

    expect($contact['BankAccountDetails'])->toBe('[redacted]')
        ->and($contact['TaxNumber'])->toBe('****2020')
        ->and($contact['Name'])->toBe('Acme');

    expect(json_encode($row->toArray()))->not->toContain('12334567');
});

it('captures a read with its where clause, but not the address inside it', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Contacts' => []], 200, xeroHeaders())]);

    XeroBridge::connection('default')->contacts()->findByEmail('jane@acme.test');

    $row = XeroApiCall::where('channel', 'xero.api')->sole();

    // The package BUILDS this clause, so the leak would be ours, not the host's.
    expect(urldecode($row->url))->toContain('EmailAddress=="[redacted]"')
        ->and($row->url)->not->toContain('jane');
});

it('captures both attempts of a 401 replay under one logical call', function () {
    Http::fake([
        // The 401 sends the client to refresh, so the identity host has to be
        // faked too or preventStrayRequests() stops the test before the replay.
        'identity.xero.com/*' => Http::response([
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 1800,
            'token_type' => 'Bearer',
        ]),
        'api.xero.com/*' => Http::sequence()
            ->push(['Message' => 'TokenExpired'], 401, xeroHeaders())
            ->push(['Invoices' => []], 200, xeroHeaders()),
    ]);

    XeroBridge::connection('default')->invoices()->list();

    // The refresh in between is itself a captured call, on its own channel --
    // which is the point of separating them.
    $identity = XeroApiCall::channel('xero.identity')->sole();

    expect($identity->status)->toBe(200)
        ->and($identity->response_body['access_token'])->toBe('[redacted]')
        ->and($identity->response_body['refresh_token'])->toBe('[redacted]')
        // What survives is what an expired-token investigation actually needs.
        ->and($identity->response_body['expires_in'])->toBe(1800)
        ->and($identity->response_body['token_type'])->toBe('Bearer');

    $rows = XeroApiCall::channel('xero.api')->orderBy('attempt')->get();

    expect($rows)->toHaveCount(2)
        // One logical call, two wire attempts -- otherwise "why did this take
        // two round trips?" cannot be answered from the table.
        ->and($rows[0]->logical_call_id)->toBe($rows[1]->logical_call_id)
        ->and($rows[0]->status)->toBe(401)
        ->and($rows[1]->status)->toBe(200)
        ->and($rows[0]->attempt)->toBe(1)
        ->and($rows[1]->attempt)->toBe(2);
});

it('captures a MyInvois check without the TIN or the identifier', function () {
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.client_id', 'id');
    config()->set('myinvois.client_secret', 'secret');

    Http::fake([
        '*/connect/token' => Http::response(['access_token' => 'lhdn-token-value', 'expires_in' => 3600]),
        '*/taxpayer/validate/*' => Http::response('', 200, ['correlationId' => 'corr-1']),
    ]);

    app(MyInvoisClient::class)
        ->validate('C25845632020', 'BRN', '201901234567');

    $validate = XeroApiCall::where('channel', 'myinvois.api')->sole();

    // The TIN is a PATH segment and the identifier a query parameter, so
    // neither is reachable by any rule that walks a decoded body.
    expect($validate->url)->toContain('/taxpayer/validate/[redacted]')
        ->and($validate->url)->not->toContain('C25845632020')
        ->and($validate->url)->not->toContain('201901234567')
        ->and(urldecode($validate->url))->toContain('idType=BRN');

    $token = XeroApiCall::where('channel', 'myinvois.token')->sole();

    // Here the secret is a named field in the BODY, not a header.
    expect($token->request_body['client_secret'])->toBe('[redacted]')
        ->and($token->request_body['client_id'])->toBe('id')
        ->and($token->response_body['access_token'])->toBe('[redacted]');

    expect(json_encode([$validate->toArray(), $token->toArray()]))
        ->not->toContain('lhdn-token-value')
        ->not->toContain('201901234567');
});

it('records nothing anywhere while capture is off', function () {
    config()->set('xero-bridge.capture.enabled', false);

    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []], 200, xeroHeaders())]);

    XeroBridge::connection('default')->invoices()->list();

    expect(XeroApiCall::count())->toBe(0);
});
