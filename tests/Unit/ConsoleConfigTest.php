<?php

declare(strict_types=1);

use Peoplelogy\XeroBridge\Http\Middleware\EnsureConsoleEnabled;

/**
 * The console's shipped config, and the gate's reading of it.
 *
 * Shipped defaults are asserted on the config SOURCE, as ConfigTest does: the
 * resolved value depends on the environment the suite happens to run in, and
 * every console test case pins its own.
 */
function consoleConfigSource(): string
{
    return (string) file_get_contents(__DIR__.'/../../config/xero-bridge.php');
}

it('ships web,auth as the console middleware', function () {
    // Any authenticated user, which is why install and the docs warn about it.
    // The console test cases drop `auth`, so this is the only place the
    // shipped value is pinned.
    expect(consoleConfigSource())->toContain("env('XERO_CONSOLE_MIDDLEWARE', 'web,auth')");
});

it('ships the console enabled expression unchanged', function () {
    // Every config published since 1.2.0 carries this same expression, and
    // what its null MEANS is decided in EnsureConsoleEnabled. That is how a
    // host with a published config gets the 1.5.0 default without editing it,
    // so the expression itself must not change.
    expect(consoleConfigSource())
        ->toContain("'enabled' => in_array(env('XERO_CONSOLE_ENABLED'), [null, ''], true)")
        ->toContain(": filter_var(env('XERO_CONSOLE_ENABLED'), FILTER_VALIDATE_BOOLEAN),");
});

it('reads the flag exactly as the gate applies it', function (mixed $value, bool $enabled, string $reason) {
    config()->set('xero-bridge.console.enabled', $value);

    expect(EnsureConsoleEnabled::enabled(app()))->toBe($enabled)
        ->and(EnsureConsoleEnabled::reason(app()))->toBe($reason);
})->with([
    'unset' => [null, false, 'XERO_CONSOLE_ENABLED is not set'],
    'empty' => ['', false, 'XERO_CONSOLE_ENABLED is not set'],
    'true' => [true, true, 'XERO_CONSOLE_ENABLED=true'],
    'the string true' => ['true', true, 'XERO_CONSOLE_ENABLED=true'],
    'the string 1' => ['1', true, 'XERO_CONSOLE_ENABLED=true'],
    'the string on' => ['on', true, 'XERO_CONSOLE_ENABLED=true'],
    'false' => [false, false, 'XERO_CONSOLE_ENABLED=false'],
    // Each of these is true under a plain (bool) cast.
    'the string false' => ['false', false, 'XERO_CONSOLE_ENABLED=false'],
    'the string off' => ['off', false, 'XERO_CONSOLE_ENABLED=false'],
    'the string no' => ['no', false, 'XERO_CONSOLE_ENABLED=false'],
    'a typo' => ['nonsense', false, 'XERO_CONSOLE_ENABLED=false'],
]);

it('never consults the environment', function (string $env) {
    config()->set('xero-bridge.console.enabled', null);
    app()['env'] = $env;

    expect(EnsureConsoleEnabled::enabled(app()))->toBeFalse()
        ->and(EnsureConsoleEnabled::reason(app()))->toBe('XERO_CONSOLE_ENABLED is not set');
})->with(['local', 'workbench', 'production']);
