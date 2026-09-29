<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * The single gate between the console and somebody's real ledger.
 *
 * A DRAFT invoice can be deleted, but an AUTHORISED one can only ever be
 * voided and stays visible in Xero for good -- so the assertion that matters
 * in every refusal below is not the error message. It is
 * Http::assertNotSent(): nothing reached Xero at all.
 */
beforeEach(fn () => connection());

/** Xero, with the organisation the guard will inspect. */
function fakeOrganisation(array $organisation): void
{
    Http::fake([
        'api.xero.com/api.xro/2.0/Organisation' => Http::response(
            ['Organisations' => [$organisation]],
            200,
            xeroHeaders(),
        ),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response(
            ['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'DRAFT']]],
            200,
            xeroHeaders(),
        ),
    ]);
}

function attemptWrite(): TestResponse
{
    return test()->postJson('/xero/console/run', [
        'action' => 'invoices.create_draft',
        'params' => ['contact_id' => 'c-1', 'description' => 'Training'],
    ]);
}

/** Nothing that creates or changes a Xero record was sent. */
function assertNothingWritten(): void
{
    Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['POST', 'PUT'], true));
}

it('refuses to write into an organisation that is not a sandbox', function () {
    config()->set('xero-bridge.console.writable_organisations', []);

    fakeOrganisation(['Name' => 'Real Trading Ltd', 'IsDemoCompany' => false]);

    attemptWrite()
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'RuntimeException');

    assertNothingWritten();
});

it('allows a Xero Demo Company with an empty allow-list', function () {
    // Xero provisions the Demo Company itself and flags it; its data is
    // disposable by definition, so it needs no configuration.
    config()->set('xero-bridge.console.writable_organisations', []);

    fakeOrganisation(['Name' => 'Demo Company (Global)', 'IsDemoCompany' => true]);

    attemptWrite()->assertOk()->assertJsonPath('ok', true);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_contains($r->url(), 'Invoices'));
});

it('allows an organisation named on the allow-list, whatever the casing', function () {
    config()->set('xero-bridge.console.writable_organisations', ['acme sandbox']);

    fakeOrganisation(['Name' => 'ACME Sandbox', 'IsDemoCompany' => false]);

    attemptWrite()->assertOk()->assertJsonPath('ok', true);
});

it('never matches an allow-list entry as a substring', function () {
    // "Acme" must not open the door to "Acme Trading Sdn Bhd". Substring
    // matching is how a real ledger eventually gets written to.
    config()->set('xero-bridge.console.writable_organisations', ['Acme']);

    fakeOrganisation(['Name' => 'Acme Trading Sdn Bhd', 'IsDemoCompany' => false]);

    attemptWrite()->assertOk()->assertJsonPath('ok', false);

    assertNothingWritten();
});

it('refuses an organisation Xero gave no name at all', function () {
    config()->set('xero-bridge.console.writable_organisations', ['Acme Sandbox']);

    fakeOrganisation(['IsDemoCompany' => false]);

    attemptWrite()
        ->assertOk()
        ->assertJsonPath('ok', false);

    assertNothingWritten();
});

it('names the env key to change when it refuses', function () {
    config()->set('xero-bridge.console.writable_organisations', []);

    fakeOrganisation(['Name' => 'Real Trading Ltd', 'IsDemoCompany' => false]);

    $message = attemptWrite()->json('error.message');

    expect($message)->toContain('Real Trading Ltd')
        ->toContain('XERO_CONSOLE_WRITABLE_ORGANISATIONS')
        // With nothing allow-listed, say so rather than printing an empty list.
        ->toContain('only a Demo Company is writable');
});

it('guards every action that writes into Xero', function (string $action, array $params) {
    config()->set('xero-bridge.console.writable_organisations', []);

    fakeOrganisation(['Name' => 'Real Trading Ltd', 'IsDemoCompany' => false]);

    test()->postJson('/xero/console/run', ['action' => $action, 'params' => $params])
        ->assertOk()
        ->assertJsonPath('ok', false);

    assertNothingWritten();
})->with([
    'first or create contact' => ['contacts.first_or_create', ['email' => 'a@b.test']],
    'create draft' => ['invoices.create_draft', ['contact_id' => 'c-1', 'description' => 'x']],
    'create paid' => ['invoices.create_paid', ['contact_id' => 'c-1', 'description' => 'x', 'payment_account_code' => '090']],
    'send to contact' => ['invoices.send_to_contact', ['id' => 'inv-1']],
]);

it('never consults the organisation for a read-only action', function () {
    // The guard costs an extra API call, so it must not fire on reads.
    Http::fake(['api.xero.com/api.xro/2.0/Invoices*' => Http::response(
        ['Invoices' => [['InvoiceID' => 'inv-1']]],
        200,
        xeroHeaders(),
    )]);

    test()->postJson('/xero/console/run', ['action' => 'invoices.find', 'params' => ['id' => 'inv-1']])
        ->assertOk()
        ->assertJsonPath('ok', true);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'Organisation'));
});
