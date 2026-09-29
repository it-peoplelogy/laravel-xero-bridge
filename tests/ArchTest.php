<?php

declare(strict_types=1);

/*
| These use only the arch API that exists identically in pest-plugin-arch 3, 4
| and 5 -- the CI matrix resolves all three. In particular do NOT use
| arch()->preset()->laravel(): the preset API changed shape between arch 3 and
| 4/5 and would pass on one leg while failing on another.
*/

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('MyInvois never reaches into the Xero implementation')
    // The module lives in this package by decision, not because the two share
    // anything. LHDN is a different authority with a different auth model, and
    // the only thing keeping that boundary real once everyone has forgotten the
    // decision is this rule. Fully-qualified names rather than a namespace
    // expectation, so it behaves identically on pest-plugin-arch 3, 4 and 5.
    ->expect('Peoplelogy\XeroBridge\MyInvois')
    ->not->toUse([
        'Peoplelogy\XeroBridge\XeroBridgeManager',
        'Peoplelogy\XeroBridge\Client\XeroHttpClient',
        'Peoplelogy\XeroBridge\Client\RetryPolicy',
        'Peoplelogy\XeroBridge\OAuth\TokenManager',
        'Peoplelogy\XeroBridge\Support\XeroConfig',
        'Peoplelogy\XeroBridge\Support\ClientRegistry',
        'Peoplelogy\XeroBridge\Models\XeroConnection',
        'Peoplelogy\XeroBridge\Exceptions\XeroBridgeException',
        'Peoplelogy\XeroBridge\Facades\XeroBridge',
        // Added with the persistence layer: the MyInvois verdict table must
        // never start reaching into the Xero write ledger or its recorders.
        'Peoplelogy\XeroBridge\Writes\XeroWriteRecorder',
        'Peoplelogy\XeroBridge\Webhooks\WebhookEventRecorder',
        'Peoplelogy\XeroBridge\Models\XeroWriteRecord',
        'Peoplelogy\XeroBridge\Models\XeroWebhookEvent',
    ]);

it('has exactly four outbound HTTP call sites, each of which captures', function () {
    // A deliberately dumb scan rather than an arch() rule: expect()->toOnlyBeUsedIn()
    // does not see `use Illuminate\Http\Client\Factory as HttpFactory`, so the
    // rule written that way passed even with a real call site removed from the
    // list. A test that cannot fail is worse than no test.
    //
    // Why this matters: a fifth call site added without capture leaves a hole in
    // xero_api_calls that nothing reveals -- you simply do not find a row you
    // expected. Whoever adds one has to come here and decide about capture.
    $users = [];

    $src = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'Illuminate\\Http\\Client\\Factory')) {
            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            $users[] = str_replace('/', '\\', $relative);
        }
    }

    sort($users);

    expect($users)->toBe([
        // Issues requests, and captures them:
        'Client\\XeroHttpClient',              // the Accounting API
        'Http\\Controllers\\XeroCallbackController', // GET /connections, which does NOT
        // go through XeroHttpClient
        'MyInvois\\MyInvoisClient',            // LHDN validate and its token
        'OAuth\\IdentityClient',               // the Xero token and revocation endpoints

        // Wiring only -- these construct the clients above and issue nothing:
        'Support\\ClientRegistry',
        'XeroBridgeServiceProvider',
    ]);
});

arch('the package never reads env() outside the config file')
    // env() returns null once a consuming app runs `php artisan config:cache`.
    // In a package that reads XERO_CLIENT_SECRET, an env() call in runtime code
    // is a production outage waiting for the first deploy that caches config.
    ->expect('Peoplelogy\XeroBridge')
    ->not->toUse(['env']);
