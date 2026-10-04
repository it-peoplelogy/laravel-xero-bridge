<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureConsoleEnabled;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'xero-bridge:install {--force : Overwrite files that already exist}';

    protected $description = 'Publish the Xero Bridge config and migrations, and print the required .env keys';

    /**
     * The shipped middleware of both route groups, which admits ANY signed-in
     * user. Only this exact list is warned about: anything longer or different
     * is a decision the host made, and the command cannot judge a gate it
     * does not know.
     */
    private const ANY_SIGNED_IN_USER = ['web', 'auth'];

    /**
     * A session and CSRF, but no authentication at all: the most exposed stack
     * either route can sit behind, so it is warned about more loudly than the
     * shipped default, never less.
     */
    private const ANYONE = ['web'];

    /**
     * The one package migration this command does not publish: MyInvois's
     * goes out under a tag of its own, myinvois-migrations.
     */
    private const MYINVOIS_MIGRATION = 'create_myinvois_validations_table';

    public function handle(XeroConfig $config): int
    {
        $this->components->info('Installing Xero Bridge.');

        // Looked for BEFORE publishing: --force overwrites such a file with
        // the package's migration, after which nothing would show it was
        // ever the host's. So --force is withheld from the migrations while
        // one of them is shadowed.
        $shadowed = Diagnostics::shadowedMigrations();
        $withheld = $this->option('force')
            && array_diff(array_keys($shadowed), [self::MYINVOIS_MIGRATION]) !== [];

        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'xero-bridge-config',
            '--force' => $this->option('force') ?: null,
        ]));
        $this->components->task('Published config/xero-bridge.php');

        // The tag carries every Xero stub (connections, webhook events, write
        // ledger, API capture), not just the connections table. Never forced
        // over a migration of the host's own: see shadowedMigration().
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'xero-bridge-migrations',
            '--force' => ($this->option('force') && ! $withheld) ?: null,
        ]));
        $this->components->task('Published the migrations');

        foreach ($shadowed as $migration => $file) {
            $this->shadowedMigration($migration, $file, $withheld);
        }

        $this->gap();
        $this->line('Add these to your .env file:');
        $this->newLine();

        foreach ($this->envKeys() as $key => $note) {
            $this->line(sprintf('  <fg=yellow>%-28s</> %s', $key.'=', $note));
        }

        [$redirectUri, $redirectProblem] = $this->redirectUri($config);

        $this->newLine();
        $this->line('Then:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Create an app at <fg=cyan>https://developer.xero.com/myapps</>');
        $this->line('  3. Register this redirect URI on it, exactly:');
        $this->line('     <fg=cyan>'.$redirectUri.'</>');
        $this->line('  4. Visit <fg=cyan>'.$this->connectUrl($config).'</> to connect an organisation');

        if ($redirectProblem !== null) {
            $this->components->warn($redirectProblem);
        }

        $this->connectRouteWarning($config);
        $this->consoleSummary($config);
        $this->webhookSummary($config);

        $this->components->warn(
            'Schedule xero-bridge:refresh-tokens with ->withoutOverlapping()->onOneServer(). '
            .'Xero rotates refresh tokens, so two concurrent refreshes invalidate each other.'
        );

        return self::SUCCESS;
    }

    /**
     * A migration of the host's own that has the name of one the package
     * publishes, so vendor:publish takes it for the package's: it maps the
     * stub onto the first file in database/migrations whose name ends in the
     * stub's name, and skips that file as already published. The package's
     * migration is then never published -- the table guard in it never runs
     * -- and --existing or --force rewrites the host's file with it.
     */
    private function shadowedMigration(string $migration, string $file, bool $forceWithheld): void
    {
        $tag = $migration === self::MYINVOIS_MIGRATION ? 'myinvois-migrations' : 'xero-bridge-migrations';

        $this->components->warn(sprintf(
            'database/migrations/%s is a migration of your own with the name of the package\'s %s migration, so '
            .'vendor:publish --tag=%s takes it for the package\'s: it does not publish the package\'s own, and '
            .'with --existing or --force it would overwrite your file. To publish the package\'s, rename your '
            .'migration -- and its row in the migrations table, if it has run -- and publish again.%s',
            OutputFormatter::escape($file),
            $migration,
            $tag,
            $forceWithheld && $tag === 'xero-bridge-migrations'
                ? ' So --force was not applied to the migrations: none was overwritten, yours included.'
                : '',
        ));
    }

    /** @return array<string, string> */
    private function envKeys(): array
    {
        return [
            'XERO_CLIENT_ID' => 'required - from your Xero app',
            'XERO_CLIENT_SECRET' => 'required - shown once, at creation',
            'XERO_REDIRECT_URI' => 'required - must match the app exactly',
            'XERO_WEBHOOK_KEY' => 'optional - only if you use webhooks',
            'XERO_SCOPES' => 'optional - must include offline_access',
            'XERO_ACCOUNT_CODE' => 'required to invoice - differs per organisation',
            'XERO_TAX_TYPE' => 'optional - leave unset for per-line tax',
            'XERO_CURRENCY' => 'optional - defaults to MYR',
            'XERO_LOCK_STORE' => 'recommended - redis/memcached/database',
        ];
    }

    /**
     * The URI to register on the Xero app, and why it is only a fallback.
     *
     * redirectUri() throws when there is nothing usable to give: the routes
     * are off and XERO_REDIRECT_URI is unset, or the URI is http on a host
     * other than localhost, or is 127.0.0.1. Its message already names the
     * fix -- precisely the one thing an administrator about to type the URI
     * into Xero needs -- so it is printed rather than swallowed, and the
     * fallback follows routes.prefix, as the callback route itself does.
     *
     * @return array{0: string, 1: ?string}
     */
    private function redirectUri(XeroConfig $config): array
    {
        try {
            return [$config->redirectUri(), null];
        } catch (Throwable $e) {
            return [
                url(trim($config->routePrefix().'/callback', '/')),
                $e->getMessage().' Step 3 shows the package\'s own callback URL in its place.',
            ];
        }
    }

    private function connectUrl(XeroConfig $config): string
    {
        return url($config->connectUrl($config->defaultConnection()));
    }

    /**
     * The connect route attaches the authorised organisation to whichever key
     * the URL names, and under the default on_key_conflict=replace that
     * repoints an existing one. Behind bare `web,auth` that is open to every
     * account the host has -- the same exposure the console is warned about,
     * on a route that is on by default.
     */
    private function connectRouteWarning(XeroConfig $config): void
    {
        if (! (bool) $config->get('routes.enabled', true)) {
            return;
        }

        $middleware = $this->middleware($config->get('routes.middleware', self::ANY_SIGNED_IN_USER));

        if ($middleware === self::ANYONE) {
            $this->components->warn(
                'Anyone, signed in or not, can connect an organisation, or repoint an existing connection '
                .'at one of their own: the connect route is behind web only, with no authentication. Set '
                .'XERO_ROUTES_MIDDLEWARE, e.g. XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero".'
            );

            return;
        }

        if ($middleware !== self::ANY_SIGNED_IN_USER) {
            return;
        }

        $this->components->warn(
            'Any signed-in user can connect an organisation, or repoint an existing connection at one '
            .'of their own: the connect route is behind web, auth only. Narrow it with '
            .'XERO_ROUTES_MIDDLEWARE, e.g. XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero".'
        );
    }

    /**
     * What the test console will actually do in this environment.
     *
     * Read from the gate itself and built from config, never from
     * Route::has(): a route cache built where the console was on carries its
     * route to every host it is deployed to, including those where the gate
     * refuses every request. The URL is assembled exactly as
     * routes/console.php assembles it.
     */
    private function consoleSummary(XeroConfig $config): void
    {
        $url = url(trim(
            $config->routePrefix().'/'.trim((string) $config->get('console.prefix', 'console'), '/'),
            '/',
        ));

        $middleware = $this->middleware($config->get('console.middleware', self::ANY_SIGNED_IN_USER));
        $behind = $middleware === [] ? '(no middleware)' : OutputFormatter::escape(implode(', ', $middleware));
        $reason = EnsureConsoleEnabled::reason($this->laravel);

        $this->gap();

        if (! EnsureConsoleEnabled::enabled($this->laravel)) {
            $this->line('Test console: off ('.$reason.')');
            $this->line('  Turn it on with <fg=yellow>XERO_CONSOLE_ENABLED=true</> in the .env of the environment that should have it.');
            $this->line('  It would be served at <fg=cyan>'.$url.'</>, behind: '.$behind);

            return;
        }

        $this->line('Test console: ON ('.$reason.')');
        $this->line('  <fg=cyan>'.$url.'</>');
        $this->line('  Behind: '.$behind);
        $this->line('  Writes into Xero: '.$this->writableOrganisations($config));

        if ($middleware === []) {
            $this->components->warn(
                'The console has no middleware: anyone who can reach the URL can open it, and with no '
                .'session it has no CSRF protection. Set XERO_CONSOLE_MIDDLEWARE, e.g. '
                .'XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero".'
            );
        } elseif ($middleware === self::ANYONE) {
            $this->components->warn(
                'The console has no authentication: anyone who can reach the URL can open it, read the '
                .'connected organisation\'s invoices and contacts, and forget its connection. Set '
                .'XERO_CONSOLE_MIDDLEWARE, e.g. XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero".'
            );
        } elseif ($middleware === self::ANY_SIGNED_IN_USER) {
            $this->components->warn(
                'Any authenticated user can open it, read the connected organisation\'s invoices and '
                .'contacts, and forget its connection. Narrow it with XERO_CONSOLE_MIDDLEWARE, e.g. '
                .'XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero".'
            );
        }
    }

    /**
     * The same list, read the same way, as the console's own write guard, so
     * the summary can never promise a different set of organisations.
     */
    private function writableOrganisations(XeroConfig $config): string
    {
        $names = array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            (array) $config->get('console.writable_organisations', []),
        )));

        if ($names === []) {
            return 'a Demo Company only';
        }

        return 'a Demo Company, plus '.OutputFormatter::escape(implode(', ', array_map(
            static fn (string $name): string => '"'.$name.'"',
            $names,
        )));
    }

    /**
     * The webhook URL, from the route when it is registered -- which is what
     * Xero will actually be answered from -- and from the same rule the route
     * file uses when it is not. Nothing to print when webhooks are off.
     */
    private function webhookSummary(XeroConfig $config): void
    {
        $this->gap();

        if (! (bool) $config->get('webhooks.enabled', true)) {
            $this->line('Webhooks are disabled, so there is no webhook URL to register. '
                .'Set <fg=yellow>XERO_WEBHOOKS_ENABLED=true</> to serve one.');

            return;
        }

        $name = $config->routeName('webhook');
        $url = Route::has($name) ? route($name) : url($config->webhookUri());

        $this->line('Webhook URL (paste into the Xero app\'s Webhooks tab):');
        $this->line('  <fg=cyan>'.$url.'</>');

        if (! str_starts_with($url, 'https://')) {
            $this->components->warn(
                'Xero only delivers webhooks to https on port 443, so this URL will not work as-is. '
                .'Set APP_URL to your public https address.'
            );
        }
    }

    /**
     * One blank line before a new block, however the previous one ended. A
     * warning box already leaves one behind it -- and puts one in front of
     * itself -- so a second blank line would read as a break that is not
     * there.
     */
    private function gap(): void
    {
        if ($this->output->newLinesWritten() < 2) {
            $this->newLine();
        }
    }

    /**
     * A middleware list as the router will apply it, trimmed, for display and
     * for comparing against the shipped default.
     *
     * @return list<string>
     */
    private function middleware(mixed $configured): array
    {
        return array_values(array_filter(array_map(
            static fn ($entry): string => is_scalar($entry) ? trim((string) $entry) : get_debug_type($entry),
            (array) $configured,
        ), static fn (string $entry): bool => $entry !== ''));
    }
}
