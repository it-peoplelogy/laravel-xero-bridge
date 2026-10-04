<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
| xero-bridge:install, read the way an administrator reads it: it is the first
| thing a new consumer sees, and what they copy into the Xero app. Every case
| runs the real command through installCommandOutput() (tests/Pest.php), which
| deletes what it published before the next test boots.
|
| Booted by WebRoutesTestCase, so routes.middleware is ['web'] and the console
| starts at the shipped default: unset, off, and with no route registered.
*/

/** Point every URL the command prints at a public https host. */
function installOnPublicHost(): void
{
    // Both halves: forceRootUrl() keeps the request's own scheme.
    URL::forceRootUrl('https://app.example.test');
    URL::forceScheme('https');
}

/**
 * A host's own migration, as `php artisan make:migration` writes one: it
 * never reads the package's config, which is how it is told from a copy of
 * the package's stub.
 */
function installHostMigration(): string
{
    return <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('xero_connections', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                });
            }
        };
        PHP;
}

/*
|--------------------------------------------------------------------------
| What it publishes
|--------------------------------------------------------------------------
*/

it('publishes the config and every Xero migration, and says so', function () {
    try {
        expect(Artisan::call('xero-bridge:install'))->toBe(0);

        $output = Artisan::output();

        // The tag carries the connections, webhook events, write ledger and
        // API capture stubs -- never only the connections table it was once
        // labelled as -- and never the MyInvois table, which has a tag of its
        // own.
        expect($output)->toContain('Published the migrations')
            ->not->toContain('xero_connections migration')
            ->and(File::exists(config_path('xero-bridge.php')))->toBeTrue()
            ->and(File::glob(database_path('migrations/*_create_xero_*_table.php')))->toHaveCount(4)
            ->and(File::glob(database_path('migrations/*_create_myinvois_validations_table.php')))->toBe([]);
    } finally {
        File::delete([config_path('xero-bridge.php'), config_path('myinvois.php')]);

        foreach (['*_create_xero_*_table.php', '*_create_myinvois_validations_table.php'] as $migration) {
            File::delete(File::glob(database_path('migrations/'.$migration)) ?: []);
        }
    }
});

it('warns that a migration of the host\'s own has a package migration\'s name', function (string $migration, string $tag) {
    // vendor:publish maps each stub onto the first file in database/migrations
    // whose name ends in the stub's name, and skips it as already there. A
    // host's own migration named that way -- `php artisan make:migration
    // create_xero_connections_table` -- keeps the package's from ever being
    // published, and --existing or --force would overwrite it.
    $own = database_path("migrations/2023_05_01_000000_{$migration}.php");
    File::put($own, installHostMigration());

    try {
        $output = (string) preg_replace('/\s+/', ' ', installCommandOutput());
    } finally {
        File::delete($own);
    }

    expect($output)->toContain(
        "database/migrations/2023_05_01_000000_{$migration}.php is a migration of your own with the name of "
        ."the package's {$migration} migration, so vendor:publish --tag={$tag} takes it for the package's: it "
        ."does not publish the package's own, and with --existing or --force it would overwrite your file. To "
        ."publish the package's, rename your migration -- and its row in the migrations table, if it has run -- "
        .'and publish again.'
    )->not->toContain('--force was not applied');
})->with([
    'connections' => ['create_xero_connections_table', 'xero-bridge-migrations'],
    'webhook replay' => ['create_xero_webhook_events_table', 'xero-bridge-migrations'],
    'write ledger' => ['create_xero_write_records_table', 'xero-bridge-migrations'],
    'API capture' => ['create_xero_api_calls_table', 'xero-bridge-migrations'],
    // Published under its own tag, which this command never publishes.
    'MyInvois' => ['create_myinvois_validations_table', 'myinvois-migrations'],
]);

it('says nothing about a copy of the package\'s own migration already published', function () {
    $published = database_path('migrations/2023_05_01_000000_create_xero_connections_table.php');
    File::copy(__DIR__.'/../../database/migrations/create_xero_connections_table.php.stub', $published);

    try {
        $output = installCommandOutput();
    } finally {
        File::delete($published);
    }

    expect($output)->not->toContain('a migration of your own');
});

it('never lets --force overwrite a migration of the host\'s own with the package\'s', function () {
    $own = database_path('migrations/2023_05_01_000000_create_xero_connections_table.php');
    File::put($own, installHostMigration());

    try {
        // Booted again with the file in place, as on a host where it was
        // there all along: only then does vendor:publish map the stub onto it.
        $this->refreshApplication();

        expect(Artisan::call('xero-bridge:install', ['--force' => true]))->toBe(0);

        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());
        $after = File::get($own);
    } finally {
        File::delete([$own, config_path('xero-bridge.php'), config_path('myinvois.php')]);

        foreach (['*_create_xero_*_table.php', '*_create_myinvois_validations_table.php'] as $migration) {
            File::delete(File::glob(database_path('migrations/'.$migration)) ?: []);
        }
    }

    expect($after)->toBe(installHostMigration())
        ->and($output)->toContain(
            'database/migrations/2023_05_01_000000_create_xero_connections_table.php is a migration of your own'
        )
        ->toContain('So --force was not applied to the migrations: none was overwritten, yours included.');
});

/*
|--------------------------------------------------------------------------
| The test console, as the gate decides it
|--------------------------------------------------------------------------
*/

it('reports the console off, and how to switch it on, when nothing is set', function () {
    installOnPublicHost();
    config()->set('xero-bridge.console.enabled', null);
    config()->set('xero-bridge.console.middleware', ['web', 'auth']);

    expect(installCommandOutput())
        ->toContain('Test console: off (XERO_CONSOLE_ENABLED is not set)')
        ->toContain('Turn it on with XERO_CONSOLE_ENABLED=true')
        ->toContain('It would be served at https://app.example.test/xero/console, behind: web, auth')
        ->not->toContain('Test console: ON')
        // Nothing is exposed while it is off, so nothing to warn about.
        ->not->toContain('Any authenticated user');
});

it('names an explicit false as the reason the console is off', function (mixed $value) {
    config()->set('xero-bridge.console.enabled', $value);

    expect(installCommandOutput())->toContain('Test console: off (XERO_CONSOLE_ENABLED=false)');
})->with([
    'false' => [false],
    // What a hand-edited config or a runtime config()->set() can deliver.
    'the string false' => ['false'],
]);

it('reports the console on, where it is served and the middleware in front of it', function () {
    installOnPublicHost();
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.middleware', ['web', 'auth', 'can:manage-xero']);

    expect(installCommandOutput())
        ->toContain('Test console: ON (XERO_CONSOLE_ENABLED=true)')
        ->toContain("\n  https://app.example.test/xero/console\n")
        ->toContain('Behind: web, auth, can:manage-xero')
        ->not->toContain('Test console: off')
        // A host that narrowed the gate made a decision; nothing to nag about.
        ->not->toContain('Any authenticated user')
        ->not->toContain('XERO_CONSOLE_MIDDLEWARE');
});

it('warns that any authenticated user can open a console behind web, auth', function () {
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.middleware', ['web', 'auth']);

    expect(installCommandOutput())
        ->toContain('Behind: web, auth')
        ->toContain('Any authenticated user can open it')
        ->toContain('invoices and contacts, and forget its connection')
        ->toContain('XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"');
});

it('warns that a console behind web alone lets anyone in', function () {
    // A session and CSRF, but no authentication: more exposed than the shipped
    // web,auth, so it must never be the stack that gets no warning.
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.middleware', ['web']);

    expect(installCommandOutput())
        ->toContain('Behind: web')
        ->toContain('The console has no authentication')
        ->toContain('anyone who can reach the URL can open it')
        ->toContain('XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"')
        ->not->toContain('Any authenticated user');
});

it('warns that a console with no middleware has no session and no CSRF protection', function () {
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.middleware', []);

    expect(installCommandOutput())
        ->toContain('Behind: (no middleware)')
        ->toContain('The console has no middleware')
        ->toContain('anyone who can reach the URL can open it')
        ->toContain('no CSRF protection')
        ->not->toContain('Any authenticated user');
});

it('says which organisations the console may write into', function (array $allowed, string $expected) {
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.writable_organisations', $allowed);

    expect(installCommandOutput())->toContain('Writes into Xero: '.$expected."\n");
})->with([
    'nothing named' => [[], 'a Demo Company only'],
    'blank entries ignored, as the guard ignores them' => [['  '], 'a Demo Company only'],
    'named sandboxes' => [['Acme Sandbox', ' Beta Trading '], 'a Demo Company, plus "Acme Sandbox", "Beta Trading"'],
]);

it('reports the gate, not whether a console route is registered', function () {
    // The route:cache case: a cache built where the console was on carries
    // its route to a host where the gate refuses every request with a 404.
    Route::get('xero/console', fn () => 'from a route cache')->name('xero-bridge.console');
    app('router')->getRoutes()->refreshNameLookups();
    config()->set('xero-bridge.console.enabled', false);

    expect(Route::has('xero-bridge.console'))->toBeTrue()
        ->and(installCommandOutput())->toContain('Test console: off')
        ->not->toContain('Test console: ON');
});

it('builds the console URL from config, as the console route file does', function () {
    installOnPublicHost();
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.routes.prefix', '/admin/xero/');
    config()->set('xero-bridge.console.prefix', '/tools/');

    expect(installCommandOutput())->toContain("\n  https://app.example.test/admin/xero/tools\n");
});

/*
|--------------------------------------------------------------------------
| The connect route
|--------------------------------------------------------------------------
*/

it('warns that any signed-in user can connect or repoint an organisation behind web, auth', function () {
    config()->set('xero-bridge.routes.middleware', ['web', 'auth']);

    expect(installCommandOutput())
        ->toContain('Any signed-in user can connect an organisation, or repoint an existing connection')
        ->toContain('XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero"');
});

it('warns that anyone at all can connect or repoint an organisation behind web alone', function () {
    config()->set('xero-bridge.routes.middleware', ['web']);

    expect(installCommandOutput())
        ->toContain('Anyone, signed in or not, can connect an organisation')
        ->toContain('behind web only, with no authentication')
        ->toContain('XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero"')
        ->not->toContain('Any signed-in user can connect');
});

it('does not warn about the connect route when there is nothing to warn about', function (array $middleware, bool $enabled) {
    config()->set('xero-bridge.routes.middleware', $middleware);
    config()->set('xero-bridge.routes.enabled', $enabled);

    expect(installCommandOutput())->not->toContain('XERO_ROUTES_MIDDLEWARE');
})->with([
    'a narrowed gate' => [['web', 'auth', 'can:manage-xero'], true],
    // The host registers its own connect route; this list guards nothing.
    'the routes disabled' => [['web', 'auth'], false],
]);

/*
|--------------------------------------------------------------------------
| The webhook URL
|--------------------------------------------------------------------------
*/

it('says webhooks are disabled instead of printing a URL', function () {
    config()->set('xero-bridge.webhooks.enabled', false);

    expect(installCommandOutput())
        ->toContain('Webhooks are disabled')
        ->not->toContain('/xero/webhook')
        ->not->toContain('Xero only delivers webhooks to https');
});

it('prints the webhook route Xero will actually be answered from', function () {
    // The route was registered at boot under xero/webhook. Config changed
    // since -- as it can under a route cache -- does not move what is served,
    // so it must not move what is printed either.
    installOnPublicHost();
    config()->set('xero-bridge.webhooks.prefix', 'api/hooks');

    expect(installCommandOutput())
        ->toContain("\n  https://app.example.test/xero/webhook\n")
        ->not->toContain('/api/hooks/webhook')
        ->not->toContain('Xero only delivers webhooks to https');
});

it('builds the webhook URL by the route file\'s rule when the route is not registered', function () {
    // A name prefix nothing was registered under, so Route::has() is false.
    installOnPublicHost();
    config()->set('xero-bridge.routes.name_prefix', 'unregistered.');
    config()->set('xero-bridge.webhooks.prefix', 'api/hooks');

    expect(installCommandOutput())->toContain("\n  https://app.example.test/api/hooks/webhook\n");
});

/*
|--------------------------------------------------------------------------
| The redirect URI fallback
|--------------------------------------------------------------------------
*/

it('falls back to the callback URL and says why when the redirect URI is not https', function () {
    installOnPublicHost();
    config()->set('xero-bridge.redirect_uri', 'http://example.test/x');

    expect(installCommandOutput())
        ->toContain("\n     https://app.example.test/xero/callback\n")
        ->toContain('Xero requires an https redirect URI; [http://example.test/x] is not')
        ->toContain('Step 3 shows the package\'s own callback URL in its place');
});

it('falls back to the prefixed callback when no redirect URI can be determined', function () {
    // Unset, and no callback route to derive one from: what a host that
    // registers its own routes and forgot XERO_REDIRECT_URI sees.
    installOnPublicHost();
    config()->set('xero-bridge.redirect_uri', null);
    config()->set('xero-bridge.routes.name_prefix', 'unregistered.');
    config()->set('xero-bridge.routes.prefix', 'accounting');

    expect(installCommandOutput())
        ->toContain("\n     https://app.example.test/accounting/callback\n")
        ->toContain('could not determine a redirect URI')
        ->not->toContain('/xero/callback');
});

it('prints a usable redirect URI as it is, with no warning', function () {
    expect(installCommandOutput())
        ->toContain("\n     https://example.test/xero/callback\n")
        ->not->toContain('Step 3 shows');
});
