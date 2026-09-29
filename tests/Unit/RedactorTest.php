<?php

declare(strict_types=1);

use Peoplelogy\XeroBridge\Support\RedactionPolicy;
use Peoplelogy\XeroBridge\Support\Redactor;

/**
 * The thing standing between a useful audit trail and a table full of bearer
 * tokens and other people's bank accounts.
 *
 * Half of these tests prove a field is REMOVED. The other half prove a field
 * SURVIVES, and those matter just as much: the bank fields share a stem with
 * the most diagnostic fields in the whole capture, so an over-eager matcher
 * would leave a table that is safe and useless.
 */
function redactionPolicy(array $add = [], array $keep = [], array $skip = []): RedactionPolicy
{
    return new RedactionPolicy(add: $add, keep: $keep, skipUrls: $skip);
}

function redactBody(array $body, string $channel = 'xero.api', ?RedactionPolicy $policy = null): array
{
    return (new Redactor($policy ?? redactionPolicy(), $channel))->body($body) ?? [];
}

/*
|--------------------------------------------------------------------------
| Bank details -- the owner's requirement
|--------------------------------------------------------------------------
*/

it('removes the organisation bank account from a chart of accounts', function () {
    $out = redactBody(['Accounts' => [
        ['Code' => '090', 'Name' => 'Business Bank Account', 'Type' => 'BANK', 'BankAccountNumber' => '0209087654321050'],
    ]]);

    expect($out['Accounts'][0]['BankAccountNumber'])->toBe('[redacted]')
        // Everything a human needs to identify the account survives.
        ->and($out['Accounts'][0]['Code'])->toBe('090')
        ->and($out['Accounts'][0]['Name'])->toBe('Business Bank Account')
        ->and($out['Accounts'][0]['Type'])->toBe('BANK');
});

it("removes the customer's own bank account echoed back on a contact", function () {
    $out = redactBody(['Contacts' => [['Name' => 'Acme', 'BankAccountDetails' => '12334567']]]);

    expect($out['Contacts'][0]['BankAccountDetails'])->toBe('[redacted]')
        ->and($out['Contacts'][0]['Name'])->toBe('Acme');
});

it('takes the whole BatchPayments object, so its siblings cannot survive', function () {
    $out = redactBody(['Contacts' => [['BatchPayments' => [
        'BankAccountNumber' => '12334567',
        'BankAccountName' => 'Citi Bank',
        'Details' => 'Acme Sdn Bhd',
    ]]]]);

    // Not an array with three redacted children -- the subtree is gone.
    expect($out['Contacts'][0]['BatchPayments'])->toBe('[redacted]');
});

it('also catches the singular BatchPayment block on a payment read', function () {
    $out = redactBody(['Payments' => [['BatchPayment' => ['BankAccountNumber' => '12334567']]]]);

    expect($out['Payments'][0]['BatchPayment'])->toBe('[redacted]');
});

it('finds a bank field nested four levels inside arrays of objects', function () {
    $out = redactBody(['Invoices' => [['Payments' => [['Invoice' => ['Contact' => [
        'Name' => 'Acme',
        'BankAccountDetails' => '12334567',
    ]]]]]]]);

    $contact = $out['Invoices'][0]['Payments'][0]['Invoice']['Contact'];

    expect($contact['BankAccountDetails'])->toBe('[redacted]')
        ->and($contact['Name'])->toBe('Acme');
});

/*
|--------------------------------------------------------------------------
| The substring trap -- fields that MUST survive
|--------------------------------------------------------------------------
*/

it('keeps BankAccountType, which explains a rejected payment', function () {
    // A substring rule on "BankAccount" would eat this for no benefit.
    $out = redactBody(['Accounts' => [['BankAccountType' => 'CREDITCARD', 'BankAccountNumber' => '123456']]]);

    expect($out['Accounts'][0]['BankAccountType'])->toBe('CREDITCARD')
        ->and($out['Accounts'][0]['BankAccountNumber'])->toBe('[redacted]');
});

it('keeps Contact.AccountNumber, which is not a bank account at all', function () {
    // Xero: "a user defined account number" -- in practice the consuming
    // project's own customer code, and the first thing support looks for.
    expect(redactBody(['Contacts' => [['AccountNumber' => 'CUST-4471']]])['Contacts'][0]['AccountNumber'])
        ->toBe('CUST-4471');
});

it('keeps the account code a payment is posted against', function () {
    // Payments::create() SENDS this. Redacting it would leave a capture row
    // that cannot answer which ledger account the payment hit.
    $out = redactBody(['Payments' => [['Account' => ['Code' => '090', 'AccountID' => 'acc-1'], 'Amount' => 120.5]]]);

    expect($out['Payments'][0]['Account']['Code'])->toBe('090')
        ->and($out['Payments'][0]['Account']['AccountID'])->toBe('acc-1');
});

it('keeps the fields that make a row worth reading', function () {
    $out = redactBody(['Contacts' => [[
        'CompanyNumber' => '201901234567',
        'EmailAddress' => 'jane@acme.test',
        'Name' => 'Acme',
    ]]]);

    expect($out['Contacts'][0]['CompanyNumber'])->toBe('201901234567')
        ->and($out['Contacts'][0]['EmailAddress'])->toBe('jane@acme.test');
});

/*
|--------------------------------------------------------------------------
| Credentials
|--------------------------------------------------------------------------
*/

it('removes every credential, on every channel', function (string $key) {
    expect(redactBody([$key => 'a-real-looking-secret-value'])[$key])->toBe('[redacted]');
})->with([
    'authorization',
    'Authorization',        // header casing is whatever the client sent
    'access_token',
    'refresh_token',
    'id_token',
    'client_secret',
    'x-xero-signature',
    'APIKey',               // on GET /Organisation, of all places
]);

it('keeps client_id, which tells sandbox from production', function () {
    expect(redactBody(['client_id' => 'ABC123', 'client_secret' => 'shhh-very-secret'])['client_id'])
        ->toBe('ABC123');
});

it('redacts an authorisation code only on a token channel', function () {
    // `code` on a token exchange is a grant...
    expect(redactBody(['code' => 'auth-code-value'], 'xero.identity')['code'])->toBe('[redacted]');

    // ...and everywhere else it is a Xero account code.
    expect(redactBody(['code' => '090'], 'xero.api')['code'])->toBe('090');
});

it('does not reach a nested Code even on a token channel', function () {
    // The OAuth form bodies are flat, so the rule stops at depth 1.
    $out = redactBody(['Accounts' => [['Code' => '090']]], 'xero.identity');

    expect($out['Accounts'][0]['Code'])->toBe('090');
});

it('redacts the revocation body, whose key is the bland word token', function () {
    expect(redactBody(['token' => 'the-refresh-token-value'], 'xero.identity')['token'])->toBe('[redacted]');
});

/*
|--------------------------------------------------------------------------
| What configuration may and may not switch off
|--------------------------------------------------------------------------
*/

it('refuses to let configuration un-redact a credential', function () {
    $out = redactBody(['access_token' => 'still-a-secret'], 'xero.api', redactionPolicy(keep: ['access_token']));

    expect($out['access_token'])->toBe('[redacted]');
});

it('refuses to let configuration un-redact a bank field', function () {
    // The owner's requirement: "save everything except bank account" may not be
    // undone by one line of config.
    $out = redactBody(
        ['Accounts' => [['BankAccountNumber' => '0209087654321050']]],
        'xero.api',
        redactionPolicy(keep: ['bankaccountnumber', 'bankaccountdetails', 'batchpayments']),
    );

    expect($out['Accounts'][0]['BankAccountNumber'])->toBe('[redacted]');
});

it('does let a project keep a tax identifier it has somewhere lawful to put', function () {
    $out = redactBody(['Contacts' => [['TaxNumber' => 'C25845632020']]], 'xero.api', redactionPolicy(keep: ['taxnumber']));

    expect($out['Contacts'][0]['TaxNumber'])->toBe('C25845632020');
});

it('lets a project add a field of its own', function () {
    $out = redactBody(['Contacts' => [['Phones' => [['PhoneNumber' => '0123456789']]]]], 'xero.api', redactionPolicy(add: ['phonenumber']));

    expect($out['Contacts'][0]['Phones'][0]['PhoneNumber'])->toBe('[redacted]');
});

/*
|--------------------------------------------------------------------------
| The TIN: masked, not blanked
|--------------------------------------------------------------------------
*/

it('masks a tax number to its last four so the row still joins to the verdict', function () {
    $out = redactBody(['Contacts' => [['TaxNumber' => 'C25845632020']]]);

    // The same four characters myinvois_validations.tin_last4 already holds.
    expect($out['Contacts'][0]['TaxNumber'])->toBe('****2020');
});

it('blanks a tax number too short to mask', function () {
    expect(redactBody(['TaxNumber' => 'C25'])['TaxNumber'])->toBe('[redacted]');
});

it('survives a tax number that is not a string', function () {
    // Host-supplied. A strict signature here would throw out of the recorder
    // and kill the Xero call it was recording.
    expect(redactBody(['TaxNumber' => 25845632020])['TaxNumber'])->toBe('****2020');

    expect(redactBody(['TaxNumber' => ['unexpected' => 'shape']])['TaxNumber'])->toBe('[redacted]');
});

/*
|--------------------------------------------------------------------------
| Echo suppression -- the leak no key rule can reach
|--------------------------------------------------------------------------
*/

it('catches a redacted value quoted back inside a validation message', function () {
    $redactor = new Redactor(redactionPolicy(), 'xero.api');

    $redactor->body(['Contacts' => [['TaxNumber' => 'C25845632020']]]);

    // Xero echoes the submitted element back with the value inside free text.
    $response = $redactor->body(['Elements' => [['ValidationErrors' => [
        ['Message' => 'TaxNumber C25845632020 is not valid'],
    ]]]]);

    expect($response['Elements'][0]['ValidationErrors'][0]['Message'])
        ->toBe('TaxNumber [redacted] is not valid');
});

it('suppresses an echoed bank number as well', function () {
    $redactor = new Redactor(redactionPolicy(), 'xero.api');

    $redactor->body(['Accounts' => [['BankAccountNumber' => '0209087654321050']]]);

    $response = $redactor->body(['Message' => 'Account 0209087654321050 was rejected']);

    expect($response['Message'])->toBe('Account [redacted] was rejected');
});

/*
|--------------------------------------------------------------------------
| URLs, paths and query strings
|--------------------------------------------------------------------------
*/

it('masks the MyInvois TIN, which lives in the URL path', function () {
    $url = (new Redactor(redactionPolicy(), 'myinvois.api'))
        ->url('https://preprod-api.myinvois.hasil.gov.my/api/v1.0/taxpayer/validate/C25845632020');

    expect($url)->toBe('https://preprod-api.myinvois.hasil.gov.my/api/v1.0/taxpayer/validate/[redacted]');
});

it('removes idValue from the query while keeping idType', function () {
    $url = (new Redactor(redactionPolicy(), 'myinvois.api'))->urlWithQuery(
        'https://preprod-api.myinvois.hasil.gov.my/api/v1.0/taxpayer/validate/C25845632020',
        ['idType' => 'BRN', 'idValue' => '201901234567'],
    );

    expect($url)->toContain('idType=BRN')
        // With idValue gone, idType is the only clue what was checked.
        ->and($url)->not->toContain('201901234567')
        ->and($url)->not->toContain('C25845632020');
});

it('masks the operand of a where clause but keeps the field it filtered on', function () {
    // This package BUILDS this clause: Contacts::findByEmail().
    $url = (new Redactor(redactionPolicy(), 'xero.api'))->urlWithQuery(
        'https://api.xero.com/api.xro/2.0/Contacts',
        ['where' => 'EmailAddress=="jane@acme.test"'],
    );

    expect(urldecode($url))->toContain('EmailAddress=="[redacted]"')
        ->and($url)->not->toContain('jane');
});

it('removes a free-text search term, which has no structure to keep', function () {
    expect(redactBody(['SearchTerm' => 'jane@acme.test'])['SearchTerm'])->toBe('[redacted]');
});

it('skips an endpoint whose only field is a capability link', function () {
    $policy = redactionPolicy(skip: ['#/OnlineInvoice(\?|$)#i']);

    expect($policy->skips('https://api.xero.com/api.xro/2.0/Invoices/abc/OnlineInvoice'))->toBeTrue()
        ->and($policy->skips('https://api.xero.com/api.xro/2.0/Invoices/abc'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| It must never be the thing that breaks
|--------------------------------------------------------------------------
*/

it('answers null for a body that is not JSON', function () {
    // Xero's documented 503 is plain text: "The Organisation is offline".
    $redactor = new Redactor(redactionPolicy(), 'xero.api');

    expect($redactor->body(null))->toBeNull()
        ->and($redactor->body('The Organisation is offline'))->toBeNull()
        ->and($redactor->body(false))->toBeNull();
});

it('stops at a depth limit rather than recursing forever', function () {
    // A request payload is built by the host, and $a['self'] = &$a is legal.
    $deep = ['v' => 'bottom'];

    for ($i = 0; $i < 40; $i++) {
        $deep = ['down' => $deep];
    }

    $out = redactBody($deep);
    $json = json_encode($out);

    expect($json)->toContain('[truncated: depth]')
        ->and($json)->not->toContain('bottom');
});

it('stops at a node budget rather than walking a giant batch forever', function () {
    $policy = new RedactionPolicy(maxNodes: 50);

    $rows = [];

    for ($i = 0; $i < 500; $i++) {
        $rows[] = ['Reference' => 'INV-'.$i];
    }

    expect(json_encode(redactBody(['Invoices' => $rows], 'xero.api', $policy)))
        ->toContain('[truncated: budget]');
});

it('does not walk into an object and fire its accessors', function () {
    $out = redactBody(['payload' => new DateTimeImmutable('2026-01-15')]);

    expect($out['payload'])->toBe('[object DateTimeImmutable]');
});

it('records which key names it hit', function () {
    $redactor = new Redactor(redactionPolicy(), 'xero.api');

    $redactor->body(['Accounts' => [['BankAccountNumber' => '123456', 'Code' => '090']]]);

    expect($redactor->hits())->toContain('bankaccountnumber')
        ->and($redactor->hits())->not->toContain('code');
});
