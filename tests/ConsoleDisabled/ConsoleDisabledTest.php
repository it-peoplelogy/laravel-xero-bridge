<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Booted with xero-bridge.console.enabled = false.
 *
 * The point of a separate test case is that these assertions are about
 * REGISTRATION, not about the request-time gate: with the console off the
 * routes must never have existed at all.
 */
it('registers no console routes when the console is disabled', function () {
    expect(Route::has('xero-bridge.console'))->toBeFalse()
        ->and(Route::has('xero-bridge.console.run'))->toBeFalse();
});

it('404s the console page when the console is disabled', function () {
    $this->get('/xero/console')->assertNotFound();
});

it('404s the run endpoint when the console is disabled', function () {
    $this->postJson('/xero/console/run', ['action' => 'status'])->assertNotFound();
});

it('leaves the OAuth routes alone when only the console is disabled', function () {
    // The three flags are independent: turning the console off must not take
    // connect, callback or the webhook with it.
    expect(Route::has('xero-bridge.connect'))->toBeTrue()
        ->and(Route::has('xero-bridge.callback'))->toBeTrue()
        ->and(Route::has('xero-bridge.webhook'))->toBeTrue();
});
