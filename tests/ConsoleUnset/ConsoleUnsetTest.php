<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureConsoleEnabled;

/**
 * Booted with xero-bridge.console.enabled = null, in `local` -- what a host
 * that never set XERO_CONSOLE_ENABLED gets (tests/Support/ConsoleUnsetTestCase.php).
 *
 * Like ConsoleDisabledTest, these are about REGISTRATION: with nothing set the
 * routes must never have existed at all, not merely refuse requests. And
 * `local` is the environment the pre-1.5.0 default switched the console on
 * for, so it is the case that would regress first.
 */
it('registers no console routes when XERO_CONSOLE_ENABLED is not set', function () {
    expect(app()->environment())->toBe('local')
        ->and(Route::has('xero-bridge.console'))->toBeFalse()
        ->and(Route::has('xero-bridge.console.run'))->toBeFalse();
});

it('404s the console page and its run endpoint', function () {
    $this->get('/xero/console')->assertNotFound();
    $this->postJson('/xero/console/run', ['action' => 'status'])->assertNotFound();
});

it('says why, in the words of the variable that changes it', function () {
    expect(EnsureConsoleEnabled::enabled(app()))->toBeFalse()
        ->and(EnsureConsoleEnabled::reason(app()))->toBe('XERO_CONSOLE_ENABLED is not set');
});

it('leaves connect, callback and the webhook registered', function () {
    // The console's flag is independent of the other two route files.
    expect(Route::has('xero-bridge.connect'))->toBeTrue()
        ->and(Route::has('xero-bridge.callback'))->toBeTrue()
        ->and(Route::has('xero-bridge.webhook'))->toBeTrue();
});
