<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * The MyInvois panel in the test console.
 *
 * Bound to the existing ConsoleTestCase. No new Testbench case is needed: the
 * other console test cases exist because routes and middleware are decided at
 * BOOT, and MyInvois registers neither — the panel and the allow-list both read
 * config at request time, so config()->set() inside a test is enough.
 */
const PANEL_SANDBOX = 'https://preprod-api.myinvois.hasil.gov.my';

function enableMyInvois(): void
{
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.environment', 'sandbox');
    config()->set('myinvois.client_id', 'test-myinvois-client-id');
    config()->set('myinvois.client_secret', 'test-myinvois-client-secret');
}

function fakePanelMyInvois(int $status = 200): void
{
    Http::fake([
        PANEL_SANDBOX.'/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        PANEL_SANDBOX.'/api/v1.0/taxpayer/validate/*' => Http::response('', $status),
    ]);
}

/*
|--------------------------------------------------------------------------
| Disabled -- the default every non-Malaysian consumer sees
|--------------------------------------------------------------------------
*/

it('shows no MyInvois panel while the module is disabled', function () {
    // Anything rendered here appears in every installation that switches the
    // console on, Malaysian or not. Not even a "MyInvois is off" note.
    $this->get('/xero/console')
        ->assertOk()
        ->assertDontSee('MyInvois', false);
});

it('keeps the action off the allow-list while disabled', function () {
    $this->postJson('/xero/console/run', ['action' => 'myinvois.validate'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('action');
});

it('leaves the action count unchanged while disabled', function () {
    // The Reference panel prints count($actions); a disabled module must not
    // inflate it.
    $this->get('/xero/console')
        ->assertOk()
        ->assertSee('allowed to run (15)', false);
});

/*
|--------------------------------------------------------------------------
| Enabled
|--------------------------------------------------------------------------
*/

it('renders the panel once enabled', function () {
    enableMyInvois();

    $this->get('/xero/console')
        ->assertOk()
        ->assertSee('LHDN MyInvois')
        ->assertSee('allowed to run (17)', false);
});

it('offers exactly the published id types', function () {
    enableMyInvois();

    $page = $this->get('/xero/console')->assertOk();

    foreach (['NRIC', 'PASSPORT', 'BRN', 'ARMY'] as $type) {
        $page->assertSee('value="'.$type.'"', false);
    }
});

it('surfaces a configuration problem in the panel', function () {
    enableMyInvois();
    config()->set('myinvois.client_secret', null);

    $this->get('/xero/console')
        ->assertOk()
        ->assertSee('MYINVOIS_CLIENT_SECRET');
});

it('validates a TIN through the console envelope', function () {
    enableMyInvois();
    fakePanelMyInvois(200);

    $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C25845632020', 'id_type' => 'BRN', 'id_value' => '201901234567'],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.matched', true)
        ->assertJsonPath('data.id_type', 'BRN');
});

it('reports a negative answer as a success with matched false', function () {
    // A 404 is the answer "no", so the envelope must stay ok:true. Reporting it
    // as a failure would tell the operator the console broke.
    enableMyInvois();
    fakePanelMyInvois(404);

    $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C25845632020', 'id_type' => 'BRN', 'id_value' => '999'],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.matched', false);
});

it('says plainly what a match does not prove', function () {
    enableMyInvois();
    fakePanelMyInvois(200);

    $note = $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C1', 'id_type' => 'BRN', 'id_value' => '2'],
    ])->json('data.note');

    expect($note)->toContain('not evidence');
});

it('works on a host with no Xero connection at all', function () {
    // The MyInvois branch runs before XeroBridge::connection() is touched, and
    // rateLimit() is action-aware, so neither path needs a connection row.
    enableMyInvois();
    fakePanelMyInvois(200);

    $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C1', 'id_type' => 'BRN', 'id_value' => '2'],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('still answers 200 with ok false when LHDN fails', function () {
    // The console's contract: it renders the diagnosis itself, so a failure is
    // never a non-2xx.
    enableMyInvois();
    fakePanelMyInvois(403);

    $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C1', 'id_type' => 'BRN', 'id_value' => '2'],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'MyInvoisException')
        ->assertJsonPath('error.status', 403);
});

it('reports no Xero rate limit for a MyInvois action', function () {
    // Reporting Xero's remaining quota after an LHDN call would be worse than
    // reporting nothing: a real number about the wrong API.
    enableMyInvois();
    fakePanelMyInvois(200);

    $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C1', 'id_type' => 'BRN', 'id_value' => '2'],
    ])
        ->assertOk()
        ->assertJsonPath('rate_limit', null);
});

it('forgets the cached token on request', function () {
    enableMyInvois();
    fakePanelMyInvois(200);

    $this->postJson('/xero/console/run', ['action' => 'myinvois.forget_token'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.forgotten', true);
});

it('never renders or returns the client secret', function () {
    enableMyInvois();
    fakePanelMyInvois(200);

    $this->get('/xero/console')->assertOk()->assertDontSee('test-myinvois-client-secret', false);

    $body = $this->postJson('/xero/console/run', [
        'action' => 'myinvois.validate',
        'params' => ['tin' => 'C1', 'id_type' => 'BRN', 'id_value' => '2'],
    ])->getContent();

    expect($body)->not->toContain('test-myinvois-client-secret')
        ->not->toContain('tok');
});
