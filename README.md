# Laravel Xero Bridge

A thin, tested Laravel wrapper around the [Xero Accounting API](https://developer.xero.com/documentation/api/accounting/overview),
built on Laravel's own HTTP client.

It handles connecting to Xero and keeping the connection alive, storing credentials encrypted, rate
limits, retries, error handling and duplicate prevention. It does **not** contain any bookkeeping logic:
your application decides when to invoice and what to put on the invoice.

Not covered: Payroll, Files, Projects, Assets. Anything the package has not wrapped is still reachable
through `XeroBridge::request()`.

It also ships one thing that is **not** Xero: an optional, disabled-by-default client for LHDN
Malaysia's MyInvois **taxpayer TIN validation**, because the TIN and business registration number it
checks are the same two values this bridge writes onto a Xero contact. It shares no code with the Xero
side, lives in its own namespace and config file, and is inert unless you switch it on. See
[MyInvois TIN validation](docs/08-myinvois-tin-validation.md).

---

## Support matrix

| | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|
| **PHP 8.2** | best-effort | supported | — Laravel 13 needs PHP 8.3+ |
| **PHP 8.3** | best-effort | supported | supported |
| **PHP 8.4** | best-effort | supported | supported |

Every cell except the impossible one runs in CI on every push.

> ### ⚠️ Laravel 11 is End of Life
>
> Laravel 11 receives no further security fixes, so it is supported here on a **best-effort** basis:
> the CI legs run and pass, but they are non-blocking.
>
> Because unpatched advisories affect the whole 11.x line, Composer may refuse a full
> `composer update` on a Laravel 11 application. That is a signal about **your** application, not
> about this package, and `--no-security-blocking` or `policy.advisories.block false` silence it
> without fixing it. `composer require` makes a minimal change and usually still succeeds.
>
> See [Laravel's support policy](https://laravel.com/docs/releases#support-policy) for current dates
> and versions, and upgrade when you can — that is a fix for your application, not a requirement of
> this package.

---

## Installation

### 1. Add the repository

The package is not on Packagist, so Composer has to be told where to find it. Add to the consuming
application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/it-peoplelogy/laravel-xero-bridge.git",
        "no-api": true
    }
]
```

`repositories` is a top-level key, alongside `require` and `require-dev`.

The repository is public, so **no authentication is needed** — no SSH key, no token, no deploy key, and
nothing to configure on a build server — as long as the entry keeps both of these:

- **The `https://` address.** Not `git@github.com:it-peoplelogy/laravel-xero-bridge.git`: GitHub refuses
  SSH without a key even for a public repository, and Composer then stops to ask for a GitHub token.
- **`"no-api": true`.** Without it Composer looks versions up through GitHub's API, which allows 60
  anonymous requests an hour. When that lookup fails — the limit used up, or a stale GitHub token saved
  on the machine — Composer asks for a token; under `--no-interaction` it falls back to cloning over
  SSH, which fails on a server with no key, or stops with an API-limit error. With `no-api`, Composer
  runs plain `git` over https and never calls the API.

With `no-api` the package is installed as a git checkout in `vendor/` — there is no zip to download. If
your deployment runs `chmod` over `vendor/`, git sees the changed files as modified and the next update
stops to ask whether to discard them, or under `--no-interaction` fails with "has uncommitted changes".
Add `"discard-changes": true` to your `config` block and Composer replaces them without asking.

The source is publicly visible so the group's applications can install it without credentials.
Visibility grants no licence: copying, modifying, distributing or using it outside Peoplelogy Group
requires prior written permission — see [LICENSE.md](LICENSE.md).

### 2. Require a tagged version

```bash
composer require peoplelogy/laravel-xero-bridge:^1.0
```

> ### ⚠️ Tagged releases are mandatory
>
> Consuming applications normally set `"minimum-stability": "stable"`. Against a `vcs` repository
> Composer derives versions from **git tags**, so an untagged package resolves only to `dev-main`, which
> is `dev` stability and will be **refused**. Tag before the first `composer require`, not after the
> first failure.
>
> If you need an unreleased commit, cut a pre-release tag (`v1.1.0-beta.1`) and require `^1.1@beta`.
> That scopes the relaxation to one package, instead of loosening `minimum-stability` application-wide.

### 3. Publish and migrate

```bash
php artisan xero-bridge:install
php artisan migrate
```

`xero-bridge:install` publishes the config and the package's four migrations — for `xero_connections`,
`xero_webhook_events`, `xero_write_records` and `xero_api_calls` — and prints every `.env` key you need,
the exact redirect URI to register, the connect URL, whether the test console is on, and your webhook
URL. The optional MyInvois module keeps its verdicts in a fifth table, `myinvois_validations`, whose
migration has a tag of its own that install never publishes:
`php artisan vendor:publish --tag=myinvois-migrations`.

Every table is created under a **bare** name, so whatever prefix your database connection sets is applied
automatically — on a connection with `'prefix' => 'app_'`, `xero_connections` becomes
`app_xero_connections`. Do not add a prefix yourself.

If one of those names is already taken in your database, set its variable to an unused bare name
**before** you migrate, in every environment — the package's models read the same variable at runtime —
and run `php artisan config:clear` first if your configuration is cached:

| Table | Variable |
|---|---|
| `xero_connections` | `XERO_DB_TABLE` |
| `xero_webhook_events` | `XERO_WEBHOOK_DEDUPE_TABLE` |
| `xero_write_records` | `XERO_WRITES_TABLE` |
| `xero_api_calls` | `XERO_CAPTURE_TABLE` |
| `myinvois_validations` | `MYINVOIS_AUDIT_TABLE` |

A migration refuses a same-named table it did not create, rather than adopting it. It stops before
changing anything, names the table, the columns it lacks and the variable to set, and is not recorded as
run, so the next `migrate` resumes at it once the name is free. Rolled back, it likewise leaves such a
table where it is.

### 4. Working on the package and an application together

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-xero-bridge",
        "options": { "symlink": true }
    }
]
```

then `composer require peoplelogy/laravel-xero-bridge:@dev`. With `symlink: true`, edits in the package
are live in the application with no reinstall.

> This must never reach a deployment: the path does not exist on the server, and `@dev` violates the
> application's `minimum-stability`. Keep it in a local-only overlay and revert before committing.

---

## Updating

```bash
composer update peoplelogy/laravel-xero-bridge
```

**Name the package.** A bare `composer update` re-resolves your entire dependency graph, which is a much
larger change than you asked for and can fail for reasons unrelated to this package — see the Laravel 11
note in the support matrix above. Naming the package updates only this one and leaves everything else on
its locked version.

With `^1.0` you receive every later `1.x` release, so this command is all that is needed to move from,
say, `1.0.1` to the newest. Read [CHANGELOG.md](CHANGELOG.md) first: it is written for the consumer and
says what changed for *you*, not what was refactored internally.

If a release raises one of the package's own requirements, Composer will refuse rather than silently
upgrade a shared dependency. Allow it explicitly when that happens:

```bash
composer update peoplelogy/laravel-xero-bridge --with-dependencies
```

`composer update peoplelogy/laravel-xero-bridge` is the whole upgrade. There is nothing to publish, and
no config to edit for a setting a release adds: it reaches your application by itself, published config
or not ([below](#your-published-config-is-never-updated)). If a release ever needs more — a migration to
run, say — its changelog entry says so and names the command.

Then deploy it as you would any other change:

- **Rebuild the caches you use.** A configuration cached before the update keeps serving the values it
  was built with, so a new setting takes effect once you run `php artisan config:cache` (or
  `config:clear`) again. The same goes for `route:cache`.
- **Restart your queue workers** with `php artisan queue:restart`, so they load the new code. Work that
  is already queued still runs: the webhook job's payload has not changed since 1.0.0, and a
  `XeroConnected` event queued for a listener before 1.5.0 arrives with `$event->actor` set to `null`.

### Your published config is never updated

`composer update` replaces the package in `vendor/`. It does **not** touch `config/xero-bridge.php` or
`config/myinvois.php`: each is yours the moment you publish it.

**A setting a release adds still reaches you.** Laravel's `mergeConfigFrom` is a shallow, top-level
merge: wherever your file defines a key, your whole array for that key wins. On its own, that would keep
a key a later release adds *inside* a block — `writes.strict` or `webhooks.prefix` in 1.5.0 — away from
anyone who had published the file, and its env variable would silently do nothing. Since 1.5.0 the
package fills that gap itself whenever the application boots: every key your file lacks, at any depth,
is added with the package's own value, read from your current `.env`.

It never changes a key you already have, whatever its value — `null`, `false`, `''` and `[]` all count as
decisions — and a list such as a middleware stack is one value, never merged entry by entry. The
`connections` block is left exactly as you wrote it, because its keys are your own connection names.
Two consequences:

- **A deleted line comes back** with the package's default, anywhere outside `connections`. To switch
  something off, set it to `null`, `false`, `''` or `[]` rather than removing the line.
- **A cached configuration is not filled**, just as Laravel's own merge is skipped for one.
  `php artisan config:cache` boots the application afresh, so a cache built after the update has every
  key; one built before it keeps the old values until it is rebuilt.

What the fill cannot do is change a key you already have. When a release renames the env variable a key
reads, or changes its default, your copy keeps reading the old one — and it fails quietly: the value you
set under the new name never reaches the package. (A key that is itself renamed arrives under its new
name with the package's value, so whatever you customised under the old name is ignored instead.)

Check for drift after any minor upgrade:

```bash
diff config/xero-bridge.php vendor/peoplelogy/laravel-xero-bridge/config/xero-bridge.php
```

Differences you made yourself are fine — that is the point of publishing. Differences you did not make
are the package moving on without you. The comments in your copy also stay as they were published, so
they can describe an older default; that changes nothing at runtime.

To take the new file, **read that diff first**, then:

```bash
php artisan vendor:publish --tag=xero-bridge-config --force
php artisan config:clear
```

`--force` overwrites, so any customisation of your own has to be reapplied afterwards. When the diff is
only upstream changes, this costs nothing.

> **A real example.** v1.1.0 renamed `XERO_DEFAULT_ACCOUNT_CODE` to `XERO_ACCOUNT_CODE` and removed its
> `'200'` fallback. An application that updated the package, renamed the key in its environment file and
> stopped there still had a published config reading `env('XERO_DEFAULT_ACCOUNT_CODE', '200')`. The
> correctly-named value was ignored and every invoice line posted to account `200` — whatever that
> happens to be in that organisation. Nothing errors. Nobody finds out until Finance reconciles.

### Migrations you have already published

Published migrations are your own files too, so `composer update` leaves them exactly as they are, and
nothing needs them changed. Two things are still worth knowing.

> ### ⚠️ A package migration recorded twice
>
> Two environments that publish the migrations at different moments get two filenames for the same
> table, and deploying both used to stop `migrate` with "table already exists" (`1050` on MySQL). A copy
> published from 1.4.3 on finds the table there and completes without touching it — so the
> `migrations` table then records **two** migrations as having created it. That is harmless until a
> rollback reaches the later one: rolling back its batch drops the live table and everything in it,
> which for `xero_connections` is every stored connection.
>
> From 1.5.0, `xero-bridge:status` warns when it finds this for one of the four Xero tables, naming both
> migrations and their batches.
> If you have one, delete the **later** file and its row in the `migrations` table, keep the first, and
> never roll back a batch that still contains it.

**Optional: republish them for the safer rollback.** From 1.5.0 a migration refuses a same-named table it
did not create, and its `down()` drops only a table it could have created (see
[Publish and migrate](#3-publish-and-migrate)). Copies published earlier keep their old code, whose
`down()` drops whatever table carries the name. To bring yours up to date:

```bash
php artisan vendor:publish --tag=xero-bridge-migrations --existing
php artisan vendor:publish --tag=myinvois-migrations --existing
```

Each file is rewritten in place under its own name, so its row in `migrations` still matches and
`migrate` has nothing to run. `--existing` touches only the migrations you already have. `--force` would
rewrite those as well, but it also publishes every migration in the tag that you never published — each
one a new migration that creates its table at your next `migrate`. Either way, any edits you made to
those files are overwritten. A migration recorded twice (above) is not cured by this: only the earlier
file is rewritten, so delete the later one as described.

> **Check `git status` afterwards.** `vendor:publish` maps each package migration onto any existing
> file in `database/migrations` whose name ends with that migration's name. So `--existing` and `--force`
> also rewrite a migration of **your own** with such a name — `2023_05_01_000000_create_xero_connections_table.php`
> from an earlier integration, say — and the package's migration is never published beside it. Restore
> any such file from version control. `xero-bridge:install` detects this, warns, naming the file, and
> refuses to force over it.

### If Composer fails with "dubious ownership"

On a server, `composer require`, `composer update` or `composer install` can stop with:

```
In GitDownloader.php line 241:

  Failed to execute git show-ref --head -d

  fatal: detected dubious ownership in repository at
  '/var/www/html/your-app/vendor/peoplelogy/laravel-xero-bridge'
```

Nothing is wrong with the package, and nothing is wrong with your repository.

**Why it happens.** Composer installs a package either as a *dist* (a plain archive) or as a *source*
(a real `git clone`). Only the source form has a `.git` directory, and only then does Composer run
`git` commands such as `git show-ref` inside `vendor/`. Since 2022 Git refuses to operate on a
repository owned by a different user than the one running it — the protection added for CVE-2022-24765
— so the moment the directory belongs to `root` (or to whoever last deployed) and Composer runs as
someone else, Git stops rather than trusting it.

You will only see this if your application asks for the source form:

```json
"config": {
    "preferred-install": {
        "peoplelogy/laravel-xero-bridge": "source",
        "*": "dist"
    }
}
```

**The fix.** Delete the clone and let Composer put it back, as the user who owns the deployment:

```bash
cd /var/www/html/your-app

ls -ld vendor/peoplelogy/laravel-xero-bridge    # who owns it
whoami                                          # who you are

sudo rm -rf vendor/peoplelogy/laravel-xero-bridge
composer install
```

`composer install` is the right command here even though something looks broken. It reinstalls from
`composer.lock` without re-resolving anything, and the new directory belongs to the user who ran it, so
the ownership mismatch is gone rather than worked around.

Git will also suggest `git config --global --add safe.directory …`. That does clear the error, but it
records an exception for one path, has to be repeated for every package and every server, and leaves
the ownership itself wrong — which will surface again the next time a deploy runs as a different user.

**The permanent fix** is to stop asking for the source form on servers. `source` exists so you can edit
a package in place inside `vendor/`, which is a local development convenience and no use in a
deployment. Dropping it removes this failure entirely:

```json
"config": {
    "preferred-install": {
        "*": "dist"
    }
}
```

> **Do not run `composer require` on a server.** It rewrites `composer.json`, so the file on the server
> no longer matches the one in your repository and the next `git pull` conflicts. Deployments should run
> `composer install --no-dev --optimize-autoloader`, which reads `composer.lock` and changes nothing
> else. Use `composer require` and `composer update` on a developer machine, commit the result, and let
> the server install it.

---

## Uninstalling

How much there is to undo depends on how far you got. Work through these in order and stop when you reach
a step that does not apply.

### If you only ran `composer require`

```bash
composer remove peoplelogy/laravel-xero-bridge
```

Then delete the `"repositories"` entry for this package from `composer.json`, which `composer remove`
leaves behind.

Nothing else exists yet — no published files, no table — so you are done.

### If you also ran `xero-bridge:install` and `migrate`

> ### ⚠️ Dropping the table does not disconnect you from Xero
>
> `xero_connections` holds encrypted OAuth tokens, and deleting them only makes *you* forget the
> connection. Xero still lists your application against that organisation, and it still counts against
> the connection limits.
>
> Disconnect properly **before** you drop the table, while you can still read the tokens:
>
> - **Per organisation** — `DELETE https://api.xero.com/connections/{id}`, using the stored
>   `connection_id`. Note that is Xero's *connection* id, not the `tenant_id`.
> - **Everything authorised in one flow** — `POST https://identity.xero.com/connect/revocation` with the
>   refresh token.
>
> Or have an administrator remove it from the organisation's connected apps in the Xero UI.

Once you have disconnected, **roll the package's migrations back BEFORE `composer remove`**, and before
you delete either config file: the migrations find their tables through the package's configuration
(`XERO_DB_TABLE` and the rest), and without it they fall back to the default names.

Roll back each migration by its own file **and batch**. `migrate:status` lists them, with each one's
batch number in brackets:

```bash
php artisan migrate:status \
    | grep -E 'create_(xero_(connections|webhook_events|write_records|api_calls)|myinvois_validations)_table'

# one per file listed, with the batch migrate:status showed for it
php artisan migrate:rollback --batch=3 \
    --path=database/migrations/2026_01_15_090000_create_xero_connections_table.php
```

Repeat the rollback for every file listed: up to four Xero migrations, plus
`create_myinvois_validations_table` if you published that one. Do not leave out `--batch`.
`migrate:rollback` first picks what to undo — your latest batch, unless `--batch` or `--step` says
otherwise — and `--path` only filters that pick, so without `--batch` nothing is rolled back unless the
file happens to be in your latest batch. Laravel lists the batch's other migrations as "Migration not
found"; they are outside `--path`, and they are left alone.

A migration published from 1.5.0 on drops only a table it could have created, so a same-named table of
your own survives the rollback. Copies published earlier drop whatever table carries the name: if you
are not certain each of those tables is the package's, republish them first (see
[Migrations you have already published](#migrations-you-have-already-published)).

You can drop the tables by hand instead — `xero_connections`, `xero_webhook_events`,
`xero_write_records`, `xero_api_calls` and `myinvois_validations`, or the names you configured, each with
your connection's prefix (for example `app_xero_connections`) — and then delete their rows from the
`migrations` table. The package declares no foreign keys.

Then remove the package and what publishing left in your application, skipping any file you never
published:

```bash
composer remove peoplelogy/laravel-xero-bridge

rm config/xero-bridge.php
rm config/myinvois.php
rm database/migrations/*_create_xero_connections_table.php
rm database/migrations/*_create_xero_webhook_events_table.php
rm database/migrations/*_create_xero_write_records_table.php
rm database/migrations/*_create_xero_api_calls_table.php
rm database/migrations/*_create_myinvois_validations_table.php
rm -r resources/views/vendor/xero-bridge     # the console view, if you published it
```

Finally, delete the `XERO_*` and `MYINVOIS_*` keys from `.env` and `.env.example`, and any
`xero-bridge:*` command from your schedule.

### If `composer remove` fails

On Laravel 11 you may see the advisory error described in the support matrix above, because removing a
package re-resolves the dependency graph. Add `--no-security-blocking`, or fix the underlying problem by
upgrading off Laravel 11.

---

## Create your own Xero app

Each environment should have its **own** Xero app. Go to
[developer.xero.com/myapps](https://developer.xero.com/myapps) and:

1. Sign in with a Xero account that has access to your organisation.
2. **Add App**, and choose **Web app** — not a Custom Connection, which is a client-credentials grant
   tied to a single organisation and does not fit this flow.
3. Give it a company URL and a redirect URI (below).
4. Copy the **Client ID**, then generate a **Client Secret**.

> The client secret is displayed **once**. If you lose it you must generate a new one, and the old one
> stops working immediately.

### Redirect URIs

The `redirect_uri` you send must match one registered on the app **exactly**, including any trailing
slash.

| Environment | Redirect URI |
|---|---|
| Local | `http://localhost:8000/xero/callback` |
| Staging | `https://staging.example.com/xero/callback` |
| Production | `https://example.com/xero/callback` |

HTTPS is required everywhere except `localhost`. **`http://127.0.0.1` is explicitly rejected by Xero** —
use `http://localhost` instead. (The package raises a clear error for this, because Xero's own is vague.)

### Scopes

With `XERO_SCOPES` unset, the package asks for every scope below.

| Scope | What it unlocks |
|---|---|
| `offline_access` | **Mandatory.** Without it Xero issues no refresh token and the connection dies after 30 minutes. |
| `accounting.settings` | Accounts, TaxRates, Organisation, BrandingThemes — the `settings()` resource, which only reads, so `accounting.settings.read` is enough |
| `accounting.contacts` | Contacts |
| `accounting.invoices` | Invoices, CreditNotes, Quotes, Items |
| `accounting.payments` | Payments, Overpayments, Prepayments |
| `accounting.attachments` | Attachments. The package wraps none of them, so this matters only for Attachments endpoints you call through `XeroBridge::request()`. |
| `openid profile email` | Nothing, in this package. They make Xero return an `id_token` describing the user who authorised, and the package discards it unread. |

> ### ⚠️ The default is broad
>
> An application that raises invoices and finds or creates the contacts on them needs only:
>
> ```dotenv
> XERO_SCOPES="offline_access accounting.invoices accounting.contacts accounting.settings.read"
> ```
>
> Add `accounting.payments` to record payments. Keep a settings scope whatever else you drop:
> discovering account codes and tax rates needs it, and so does the test console's write guard, which
> reads the Organisation.

> ### ⚠️ Scopes are fixed at authorisation time
>
> Editing `XERO_SCOPES` changes only the **next** consent. A token refresh asks for no scopes, so every
> existing connection keeps exactly what it was granted.
>
> A widened scope reaches a connection when it reconnects: deploy the change *before* asking anyone to
> reconnect, or they will have to do it twice. A narrowed one never does, because Xero only ever adds
> consented scopes. Shedding one means revoking the authorisation — which disconnects every organisation
> authorised in the same flow; see [Uninstalling](#uninstalling) — and connecting again.

> ### ⚠️ Broad scopes retire in September 2027
>
> `accounting.transactions` is replaced by `accounting.invoices` + `accounting.payments` +
> `accounting.banktransactions` + `accounting.manualjournals`. Since March 2026 Xero has assigned
> granular scopes to all Web and PKCE apps, new and existing alike. This package ships granular scopes
> by default — do not add broad ones back.

---

## Configuration

The keys you are most likely to set, and what breaks if they are wrong. For more, see
[the configuration reference](docs/01-getting-started.md#configuration); `config/xero-bridge.php` itself
lists every key.

| Key | Required | Notes |
|---|---|---|
| `XERO_CLIENT_ID` | yes | From your Xero app. |
| `XERO_CLIENT_SECRET` | yes | Shown once at creation. A wrong value fails as `invalid_client`, not as an expired connection. |
| `XERO_REDIRECT_URI` | yes | Must match the app exactly. https, except `http://localhost`. |
| `XERO_WEBHOOK_KEY` | if using webhooks | From the app's Webhooks tab. It is what turns webhooks on: unset, no webhook route is registered, which is all an application that only calls Xero needs. Rebuild `config:cache` and `route:cache` after setting or removing it. See [Webhooks](#webhooks). |
| `XERO_WEBHOOK_PREFIX` | no | The webhook's own prefix, in place of `XERO_ROUTES_PREFIX`: `api/v1/xero` serves it at `/api/v1/xero/webhook`, and `/` at the site root. Unset or empty follows `XERO_ROUTES_PREFIX`. Moving the URL means re-entering it in the Xero app and rebuilding `route:cache`. |
| `XERO_WEBHOOK_UNKNOWN_TENANTS` | no | `dispatch` (the default) or `ignore`: whether events for an organisation with no stored connection here reach your listeners. See [Webhooks](#webhooks). |
| `XERO_SCOPES` | no | Space separated. Must include `offline_access`. The default is broad — see [Scopes](#scopes). |
| `XERO_DEFAULT_CONNECTION` | no | Defaults to `default`. |
| `XERO_ROUTES_ENABLED` | no | Defaults to true. `route:cache` bakes in whatever this was at cache time. |
| `XERO_ROUTES_PREFIX` | no | Defaults to `xero`. The webhook's prefix too, unless `XERO_WEBHOOK_PREFIX` is set. |
| `XERO_ROUTES_MIDDLEWARE` | no | Defaults to `web,auth`, which lets any signed-in user connect — see [Who can connect](#who-can-connect). The flow needs a **session**. |
| `XERO_AFTER_CONNECT_REDIRECT` | no | Where the callback sends the user. |
| `XERO_HTTP_TIMEOUT` | no | Seconds, default 30. |
| `XERO_HTTP_RETRIES` | no | **Total** attempts including the first, default 3. |
| `XERO_LOCK_STORE` | recommended | The cache store for the token-refresh and webhook-retry locks. `array` locks only inside one process and `file` only within one server, not across servers: with more than one, use redis, memcached or database. Unset or empty means the default cache store. See below. |
| `XERO_WRITES_LEDGER` | no | Default false. Durable duplicate protection for writes named with `for($model)`; needs its migration. See [the persistence docs](docs/09-persistence.md#write-dedupe). |
| `XERO_WRITES_STRICT` | no | Default false. With the ledger on, a write whose claim cannot be recorded — its table missing, the database unreachable — is refused with `XeroWriteLedgerUnavailableException` and nothing is sent, instead of going out unprotected. Applies only to writes named with `for()`. |
| `XERO_CONSOLE_ENABLED` | no | The test console is off unless this is `true`, in every environment. See [The test console](#the-test-console). |
| `XERO_CONSOLE_MIDDLEWARE` | no | Defaults to `web,auth`, which admits any authenticated user. Narrow it wherever the console is on. |
| `XERO_CONSOLE_WRITABLE_ORGANISATIONS` | no | Organisations the console may write into, besides a Xero Demo Company. |
| `XERO_ACCOUNT_CODE` | to invoice | **No default, on purpose.** Account codes differ per organisation — one org's sales account may be `200`, another's `410002-001`. Discover yours with `settings()->accounts()`. |
| `XERO_TAX_TYPE` | no | Deliberately unset. See the tax note below. |
| `XERO_CURRENCY` | no | Default `MYR`. |
| `XERO_BRANDING_THEME_ID` | no | Per organisation. |

### `.env.example`

```dotenv
# --- Xero app credentials (developer.xero.com/myapps) -----------------------
# Never commit real values. The client secret is shown once, at creation.
XERO_CLIENT_ID=
XERO_CLIENT_SECRET=

# Must match a redirect URI registered on the Xero app EXACTLY, including any
# trailing slash. https everywhere except http://localhost.
XERO_REDIRECT_URI="${APP_URL}/xero/callback"

# Granular scopes. offline_access is mandatory or you get no refresh token.
XERO_SCOPES="openid profile email offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings accounting.attachments"

# Webhooks are Xero calling this application. While this is blank no webhook
# route is served, so an application that only calls Xero needs neither line.
# To receive them, paste the key from the Xero app's Webhooks tab.
XERO_WEBHOOK_KEY=
# Events for an organisation with no stored connection here -- one connected
# to the same Xero app from another environment, say. dispatch (the default)
# hands them to your listeners like any other; ignore skips them first.
XERO_WEBHOOK_UNKNOWN_TENANTS=dispatch

# The cache store for the token-refresh and webhook-retry locks. array locks
# only inside one process and file only within one server -- with more than
# one server, use redis, memcached or database.
XERO_LOCK_STORE=redis

# --- Invoice defaults (these differ per Xero organisation) ------------------
# No default. Find yours with settings()->accounts() -- e.g. 200, 4000, 410002-001
XERO_ACCOUNT_CODE=
XERO_CURRENCY=MYR
# Leave unset unless every line of every invoice carries the same rate.
XERO_TAX_TYPE=

# --- HTTP -------------------------------------------------------------------
XERO_HTTP_TIMEOUT=30
XERO_HTTP_RETRIES=3

# --- Test console -----------------------------------------------------------
# Off unless this is true, in every environment -- APP_ENV plays no part.
# Set it to true only in the .env of an environment that should have it.
XERO_CONSOLE_ENABLED=
# Who gets in once it is on. The default, web,auth, means ANY authenticated
# user -- narrow it to your own admin gate, e.g. "web,auth,can:manage-xero" or
# "web,auth,role:admin".
XERO_CONSOLE_MIDDLEWARE="web,auth"
# Exact organisation names the console may WRITE into, comma separated. A Xero
# Demo Company is always writable; anything else is somebody's real ledger.
XERO_CONSOLE_WRITABLE_ORGANISATIONS=

# --- MyInvois TIN validation (Malaysia) -------------------------------------
# Verifies a buyer's TIN against LHDN before you write it onto a Xero contact.
#
# The package default is FALSE. The line below is set to true because that is
# what you write when you want the feature. It also needs the two values below
# and its OWN config published:
#     php artisan vendor:publish --tag=myinvois-config
# Leaving it true with those blank is not fatal: nothing else changes, the test
# console (where it is on) shows what is missing, and a validate call throws
# naming the key.
MYINVOIS_ENABLED=true
# sandbox or production. Each issues its OWN client id and secret, so switching
# this without also switching those is a misconfiguration, not a promotion.
MYINVOIS_ENVIRONMENT=sandbox
# From the MyInvois portal, under the taxpayer's ERP registration.
MYINVOIS_CLIENT_ID=
MYINVOIS_CLIENT_SECRET=

# --- API call capture -------------------------------------------------------
# Records every Xero and LHDN request and response into xero_api_calls, for
# YOUR dashboard to read. Bank details and credentials are removed before the
# insert and cannot be switched back on.
#
# The package default is FALSE. The line below is set to true because that is
# what you write when you want the feature -- it also needs the migrations
# published and run, or nothing is recorded and a warning names the command.
XERO_CAPTURE=true
# all | writes | errors. `writes` skips successful reads, never a failure.
XERO_CAPTURE_MODE=writes
# Pruned by xero-bridge:prune -- schedule it, or the table grows forever.
XERO_CAPTURE_RETAIN_DAYS=90
```

---

## Connecting an organisation

Send an administrator to `/xero/connect`. They sign in, choose which organisations to authorise, and
Xero returns them to your callback. The package exchanges the code, reads the tenant list, stores the
connection with both tokens **encrypted at rest**, and fires `XeroConnected`. Its `$actor` names the user
who was signed in at the time, as an `id`, a `type` and a `guard` — or is `null` when it cannot say, as
when nobody was.

### Who can connect

The routes are behind `web,auth` by default, which means **any signed-in user** of your application can
connect an organisation — and, because the default `XERO_ON_KEY_CONFLICT=replace` treats a key as a slot
to fill, repoint an existing connection, `default` included, at an organisation of their own.
`xero-bridge:install` warns about this while the middleware is exactly `web,auth`, and more loudly while
it is `web` alone, which lets anyone in, signed in or not. Put your own admin gate in front of the routes:

```bash
XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero"
```

`XERO_ON_KEY_CONFLICT=error` additionally refuses to repoint a key that already holds a different
organisation.

### Sessions, SPAs and API-only applications

Connect and callback need a **session**. The connect route keeps a one-time state value in it, and the
callback accepts Xero's answer only in the session that asked for it. The browser comes back from Xero
by a plain redirect, which carries your cookies and never a bearer token, so whatever the application:

- keep something that starts a session — the `web` group — in `XERO_ROUTES_MIDDLEWARE`;
- use a session driver that persists between requests, never `array`;
- keep `SESSION_SAME_SITE=lax`, Laravel's default. Under `strict` the browser leaves the session cookie
  off the redirect back from Xero, so the callback finds neither the signed-in user nor the state, and
  every connect fails.

A Sanctum SPA that signs in with the session cookie works as it is, with
`XERO_ROUTES_MIDDLEWARE="web,auth:sanctum"`. Bearer tokens alone cannot carry the flow: the request that
comes back from Xero has no token to check.

### Multiple organisations

Each connection has a short key. `/xero/connect` uses `default`; `/xero/connect/acme` uses `acme`.

```php
XeroBridge::invoices()->create($invoice);                    // the default connection
XeroBridge::connection('acme')->invoices()->create($invoice); // a named one
```

`connection()` returns a scoped clone, so it never leaks into later calls in the same request.

```bash
php artisan xero-bridge:status     # what is connected, and how healthy
```

> ### ⚠️ Connection limits
>
> Two different caps apply, and they are often confused:
>
> - **How many organisations your app may connect to** is set by your Xero **developer-account tier**
>   (Starter allows far fewer than Core).
> - **How many uncertified apps a single organisation may connect to** is capped separately. An
>   internal business system can never be certified — that requires a public app-store listing.
>
> A connection over either limit is **refused**, not degraded.
>
> Check the numbers for your plan against Xero's
> [API limits](https://developer.xero.com/documentation/guides/oauth2/limits/) before onboarding more
> systems, and keep a record of which applications hold the allowance. Xero's own pages have disagreed
> on the exact figures, so confirm in the Xero console rather than trusting a number here.

---

## The test console

The package also ships a page at **`/xero/console`** — **off unless you switch it on**. Nothing to
publish, no build step, no assets: it renders as a standalone HTML document, so it looks and behaves the
same in an Inertia app, a Livewire app or an API-only one, and cannot disturb your own styling.

It shows the configuration health, a pre-flight check of the consent flow and the token-refresh lock,
every stored connection with its expiry and scopes, and it runs the flows by hand — the reference
lookups you need before a first invoice, find-or-create contact, create a DRAFT, create and pay, set tax
numbers and have Xero email an invoice, find and list, force a token refresh, forget a connection. Every
response comes back as JSON with timings and Xero's rate-limit headers.

### Turning it on and off

The console is off unless `XERO_CONSOLE_ENABLED=true`, in every environment. Nothing else switches it
on — not `APP_ENV`, and not a missing value:

| `XERO_CONSOLE_ENABLED` | The console |
|---|---|
| unset or empty (the default) | off |
| `true` — or `1`, `on`, `yes` | **on**, in any environment, production included |
| `false`, or anything else | off |

Before 1.5.0 an unset value meant on everywhere except `APP_ENV=production`. Set it where you want the
page, then run `php artisan config:clear` and `php artisan route:clear` (or rebuild those caches).
`xero-bridge:install` and `xero-bridge:status` both say which state it is in, and why.

The route is registered only when the console is enabled, *and* the gate is re-checked on every request.
That second check is what matters: `php artisan route:cache` bakes in whatever routes existed at cache
time, so a cache built where the console was on would otherwise carry it to every host it is deployed
to. Because the route does not exist while the console is off, guard any link to it with
`Route::has('xero-bridge.console')`.

### Who can reach it

Once it is on, the default middleware is `web,auth`, which means **any authenticated user** — and
whoever gets in can read the connected organisation's invoices and contacts, force a token refresh and
forget the stored connection. `xero-bridge:install` warns while the console is on behind exactly
`web,auth`. Narrow it to your own admin gate:

```bash
XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"     # a Gate ability
XERO_CONSOLE_MIDDLEWARE="web,auth,role:admin"          # spatie/laravel-permission
```

Whatever gate the console replaced in your own application, keep it at least as narrow.

Keep something that starts a **session** in that list. The page posts a CSRF token, and without a session
there is no CSRF protection on an endpoint that can create invoices.

If your application has no `login` route — an API-only or custom-auth app — the stock `auth` middleware
throws `RouteNotFoundException` rather than redirecting, so an unauthenticated visit is a 500. Point
`XERO_CONSOLE_MIDDLEWARE` at a guard that suits you (`web,auth:sanctum`), or define a `login` route.

### What reaches the page

Your access tokens, refresh tokens and client secret never do. Credentials are reported as booleans
(`client_secret_set: true`) and nothing else.

Xero's own payloads come back as Xero sent them — checking them is what the page is for — except for
the credential and bank fields that API capture always removes. Organisation `APIKey`, an account's
`BankAccountNumber`, a contact's `BankAccountDetails` and `BatchPayments`, `BankAccountName`, and the
OAuth tokens and client secret by name (`access_token`, `refresh_token`, `id_token`, `client_secret`,
`Authorization`) are replaced with `[redacted]` (your `XERO_CAPTURE_PLACEHOLDER`, if you set one) wherever
they appear, along with everything beneath them. Tax numbers, email addresses, phone numbers, postal
addresses and invoice data are shown as they are.

**Forget connection** deletes the stored row, which takes the integration offline: every call through
that key fails until someone reconnects. Nothing is revoked at Xero, so the authorisation stays live
there until it is removed in Xero. Each forget is logged as a warning naming the connection and, when
someone is signed in, who did it.

### The write guard

Reads run against whatever is connected. **Writes are refused** unless the connected organisation is
either a Xero Demo Company — which Xero provisions itself, flags with `IsDemoCompany`, and whose data is
disposable — or named explicitly:

```bash
XERO_CONSOLE_WRITABLE_ORGANISATIONS="Acme Sandbox,Another Sandbox"
```

Matched on the full name, case-insensitively, never as a substring. The list is empty by default, so out
of the box only a Demo Company can be written to. A sandbox organisation you create by hand is
indistinguishable from a real ledger over the API, which is why it has to be named.

### Customising the page

Publish it with `php artisan vendor:publish --tag=xero-bridge-views`. You will normally only need this
for a strict `Content-Security-Policy`: the page carries its CSS and JS inline, so a policy of
`script-src 'self'` with no `'unsafe-inline'` leaves it rendered but unstyled and inert, with the
explanation only in the browser console. A published copy is yours and does not change when you update:
to take a release's changes to the page, republish it with `--force` and reapply your own edits.

Full detail in [the console documentation](docs/07-test-console.md).

---

## MyInvois TIN validation (Malaysia, off by default)

Malaysian e-invoicing requires a buyer's TIN to be real. LHDN's MyInvois API answers that question,
and this package ships a small client for it:

```php
use Peoplelogy\XeroBridge\MyInvois\Facades\MyInvois;
use Peoplelogy\XeroBridge\MyInvois\IdType;

$valid = MyInvois::validate('C25845632020', IdType::BRN, '201901234567');
```

`true` if HASiL holds that TIN paired with that identifier, `false` if it does not. A negative answer
is **not** an exception — it is the answer you asked for.

**You must pass both values.** Since 1 August 2026 LHDN validates the TIN and the identifier as a
*pair*, so a valid TIN submitted with a stale registration number fails exactly like a fabricated one.

**A match proves the pair exists in HASiL's records and nothing more.** The endpoint returns no name
and no address, so it is not identity verification.

### Turning it on

```bash
MYINVOIS_ENABLED=true
MYINVOIS_ENVIRONMENT=sandbox
MYINVOIS_CLIENT_ID=...
MYINVOIS_CLIENT_SECRET=...
```

then `php artisan config:clear`. Optionally publish the annotated config:

```bash
php artisan vendor:publish --tag=myinvois-config
```

Note this is a **separate tag** from `xero-bridge-config`, and `xero-bridge:install` does not run it —
so republishing one config can never overwrite the other.

With it on, the [test console](docs/07-test-console.md), wherever that is switched on as well, grows a
panel for running a validation by hand.

Full detail, including caching, the error contract and troubleshooting, is in
[the MyInvois documentation](docs/08-myinvois-tin-validation.md).

---

## API call capture (off by default)

Every Xero and LHDN request and response, recorded into a table **your own application** can read and
render. The package ships no viewer for it: your project has its own roles and its own idea of who
may look at customer data, and a package cannot know either. `/xero/console` is unchanged and still a
developer tool.

```bash
php artisan vendor:publish --tag=xero-bridge-migrations
php artisan migrate
```

```dotenv
XERO_CAPTURE=true
```

Then read it through the model, and schedule the prune:

```php
use Peoplelogy\XeroBridge\Models\XeroApiCall;

XeroApiCall::forOwner($order)->latest()->get();   // everything we sent for one of YOUR records
XeroApiCall::failed()->latest()->paginate(50);    // what went wrong, including calls that never answered
```

```php
Schedule::command('xero-bridge:prune')->dailyAt('02:00');   // captured calls are kept 90 days by default
```

To attribute calls to one of your records, name it:

```php
app(ApiCallRecorder::class)->forOwner($order, fn () => XeroBridge::invoices()->create([...]));
```

**What never reaches the table.** Redaction happens before the insert, never on the way out — a raw
row would reach the binary log, the nightly backup and every `SELECT *` in a support tool. Removed:
the bearer token and every other credential, your organisation's bank account numbers, your
customers' (`BankAccountDetails`, echoed back on every contact read), the whole `BatchPayments`
block, and the MyInvois TIN and identifier, which live in the URL path and query string rather than
in any body. **Credentials and bank fields cannot be switched back on by configuration.**

**What is deliberately kept**, because the alternative is a table that is safe and useless:
`Contact.AccountNumber` — which is *not* a bank account, it is normally your own customer code —
plus `BankAccountType`, `Account.Code`, `CompanyNumber`, `correlationId`, `Idempotency-Key` and
`Xero-tenant-id`. `Contact.TaxNumber` is masked to its last four so a row still joins to its
`myinvois_validations` verdict.

One caveat worth knowing: this is **not a ledger**. The row is written after the response, so a
worker killed mid-call leaves no row. "Did that invoice reach Xero?" is answered by
`xero_write_records`, which is inserted *before* the request for exactly that reason.

Full detail, including every field in the exclusion list and why, is in
[the capture documentation](docs/10-api-capture.md).

---

## Keeping tokens alive

Access tokens last **30 minutes**. Refresh tokens **rotate**: using one invalidates it and returns a new
one, which must be saved. An unused refresh token dies after **60 days**.

The package refreshes automatically before any call that needs it. Schedule this as well, so a connection
that is idle for weeks does not lapse:

```php
Schedule::command('xero-bridge:refresh-tokens')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

> ### ⚠️ `withoutOverlapping()` and `onOneServer()` are not optional
>
> Two processes refreshing the same connection concurrently each invalidate the other's token, and the
> connection dies. The package also takes a per-connection cache lock, but that lock is only as good as
> your cache store: **`array` locks only inside one process, and `file` only within one server — not
> across servers.** Set `XERO_LOCK_STORE` to redis, memcached or database; `file` is enough only while
> the scheduler, the queue workers and the web servers all share one server. The package logs a warning,
> once, if the store cannot lock at all, and `xero-bridge:status` reports an `array` or `file` store.

If a refresh fails transiently — a 5xx, a timeout — nothing is changed and it is retried later.
**Credentials are never deleted.** Only a genuine `invalid_grant` marks a connection as needing
re-authorisation, which fires `ConnectionExpired` (once) and is cleared by reconnecting. Hang your
alerting on that event.

If your application rotates `APP_KEY`, every stored token becomes undecryptable and every organisation
must reconnect — unless the old key is kept in `APP_PREVIOUS_KEYS`, which keeps the stored tokens
readable. The package reports this as a clear configuration error rather than a stack trace, and
`xero-bridge:status` shows such a connection as `unreadable` and exits `1`.

---

## Usage

### Settings first

Start here, not with invoices: you cannot build a line item without an account code, and **account codes
and tax types differ per organisation**.

```php
$settings = XeroBridge::settings();

$settings->accounts();          // the chart of accounts
$settings->paymentAccounts();   // only those a payment can be applied to
$settings->taxRates();          // the org's real TaxType codes and rates
$settings->organisation();      // base currency, country, timezone
```

> ### ⚠️ Never hardcode an account code or tax type
>
> A `200` sales account in one organisation may be `4000` in another. Read them per tenant with
> `settings()`, cache them in your own application, and re-read when a create fails with a validation
> error naming the code.
>
> **Malaysian SST is per line, not per invoice**: training is 8%, while education and rental or leasing
> are 6%. A single invoice that recharges a venue alongside training carries two rates. That is why
> `XERO_TAX_TYPE` is unset by default, and why the package never adds a tax type to a line that
> already states one. Set a connection-wide default only if every line of every invoice is genuinely the
> same rate.

### Contacts

```php
XeroBridge::contacts()->findByEmail('finance@acme.test');
XeroBridge::contacts()->firstOrCreate(['EmailAddress' => 'finance@acme.test'], ['Name' => 'Acme Sdn Bhd']);
```

`firstOrCreate()` looks the contact up and then creates it with `PUT`, which is create-only. It
deliberately avoids `POST`'s implicit upsert: Xero warns that contact names are no longer guaranteed
unique, and with `POST` the loser of a race silently overwrites the winner's record.

### Invoices

```php
$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => $contactId],
    'Reference' => $ccfCode,
    'Status' => 'DRAFT',
    'LineItems' => [[
        'Description' => 'Leadership training, 2 days',
        'Quantity' => 1,
        'UnitAmount' => 4800,
        'TaxType' => 'OUTPUT',     // per line
    ]],
]);
```

Missing `AccountCode`, `TaxType`, `CurrencyCode` and `BrandingThemeID` are filled from the connection
defaults. Anything you supply is left alone.

> **`Type` is never guessed.** ACCREC is a sale, ACCPAY is a bill; defaulting would post a bill as a sale
> on the one occasion someone meant the other, which Finance then has to journal back out.

> **Send only `ContactID` in the `Contact` block.** Xero applies any other field to the *contact record*
> and **deletes any `ContactPersons` you did not include**. The package refuses this by default; chain
> `withContactMutation()` in front of the call if you genuinely mean it —
> `XeroBridge::invoices()->withContactMutation()->create($invoice)`.

Other operations:

```php
$invoices = XeroBridge::invoices();

$invoices->find('INV-01514');        // by number or by InvoiceID
$invoices->authorise($id);
$invoices->void($id);                // AUTHORISED only, and only with no payments
$invoices->delete($id);              // DRAFT or SUBMITTED
$invoices->email($id);               // asks Xero to send it
$invoices->pdf($id);                 // raw PDF bytes
$invoices->onlineUrl($id);
```

There is no HTTP DELETE for invoices — both `void()` and `delete()` are status changes, and the package
checks the transition is legal before sending it.

`update()` refuses a `LineItems` array whose lines lack `LineItemID`: Xero deletes and recreates any line
without one, and deletes any line you leave out. Chain `replacingLineItems()` to opt in:

```php
XeroBridge::invoices()->replacingLineItems()->update($id, $invoice);
```

Both opt-ins return a **copy** of the resource with that guard relaxed, the way `connection()` returns a
scoped clone. The opt-in covers the calls made through that copy and nothing else, so on a line of its
own it does nothing: the guard still refuses the payload, and nothing is sent.

### Filtering

```php
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

$filter = InvoiceFilter::make()
    ->statuses(['AUTHORISED', 'PAID'])
    ->type('ACCREC')
    ->dateBetween('2026-01-01', '2026-03-31')
    ->orderBy('Date', 'DESC')
    ->pageSize(100);

$page = XeroBridge::invoices()->list($filter);          // one page
$all  = XeroBridge::invoices()->all($filter);           // LazyCollection, pages as you iterate
```

For incremental sync use `modifiedSince()`, which Xero recommends over a `where` on `UpdatedDateUTC`:

```php
InvoiceFilter::make()->modifiedSince($lastSyncedAt);
```

`all()` returns a `LazyCollection`, so `->take(5)` fetches one page, not all of them.

### Payments

```php
XeroBridge::payments()->createForInvoice($invoiceId, 4800, '090');
XeroBridge::payments()->delete($paymentId);   // payments cannot be modified, only reversed
```

The account must be of type `BANK` or have "enable payments to this account" switched on — use
`settings()->paymentAccounts()` to find valid ones. The invoice must be `AUTHORISED`; Xero flips it to
`PAID` itself.

### Anything else

```php
XeroBridge::request('GET', 'CreditNotes', [], ['If-Modified-Since' => '2026-01-01T00:00:00']);
XeroBridge::raw('GET', 'Reports/BalanceSheet');   // the raw Response
```

---

## Queued invoice creation

```php
class CreateXeroInvoice implements ShouldQueue
{
    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        // 1. Already done? Nothing to do.
        if ($order->xero_invoice_id !== null) {
            return;
        }

        // 2. Belt and braces: has one already been created under our reference?
        $existing = XeroBridge::invoices()
            ->list(InvoiceFilter::make()->reference($order->reference));

        if ($existing !== []) {
            $order->update(['xero_invoice_id' => $existing[0]['InvoiceID']]);

            return;
        }

        $invoice = XeroBridge::invoices()->create([...], "order-{$order->id}");

        // 3. Persist immediately. THIS is the real dedupe.
        $order->update(['xero_invoice_id' => $invoice['InvoiceID']]);
    }
}
```

> ### ⚠️ Idempotency keys are retained for six minutes only
>
> The package sends an `Idempotency-Key` on every write, and it genuinely protects an immediate
> transient-network retry. It does **not** deduplicate a queued job retried later: after six minutes the
> key has expired and Xero treats the request as brand new, so **you get a duplicate invoice**. Almost
> any realistic backoff exceeds six minutes.
>
> Treat the key as an optimisation for transient failures, and make step 3 above — persisting the
> returned `InvoiceID` — your actual defence. Keys are also capped at 128 characters, and are checked
> *after* rate limiting, so duplicate requests still consume quota.

---

## Rate limits

Per organisation: 60 calls per minute, 5 concurrent, and a daily cap that depends on your plan. Across
your whole app: 10,000 per minute.

The package retries `429` and `5xx` automatically, honouring `Retry-After`. Two cases it deliberately
does not absorb, because blocking a worker for hours is worse than failing:

```php
try {
    XeroBridge::invoices()->create($invoice);
} catch (XeroRateLimitException | XeroServiceUnavailableException $e) {
    $this->release($e->retryAfter() ?? 300);
}
```

`XeroBridge::client()->lastRateLimit()` exposes the remaining quota from the last response.

---

## Webhooks

**Only needed when Xero calls your application.** A webhook is Xero telling you that something changed
inside Xero. An application that only calls Xero needs none of this section and no webhook setting: with
no `XERO_WEBHOOK_KEY`, the webhook route is not registered, nothing needs setting up in the Xero app, and
`xero-bridge:status` has nothing to say about it. Without a key the endpoint could only answer 401 to
every delivery, so serving it would add an open endpoint and nothing else.

To receive them, register the URL printed by `xero-bridge:install` in the Xero app's **Webhooks** tab —
`/xero/webhook` by default: the route prefix plus `webhook`, or `XERO_WEBHOOK_PREFIX` in place of the
route prefix when you give the webhook one of its own. Put the signing key Xero shows into
`XERO_WEBHOOK_KEY` and deploy it before you press **Send "Intent to receive"**, since the route exists
only once the key is set. `XERO_WEBHOOKS_ENABLED=false` — `false` or `0`, since `off` and `no` read as
on — is a kill switch that removes the route even then. Both are read when routes are registered, so
rebuild `config:cache` and `route:cache` after setting or removing either. Then listen:

```php
Event::listen(XeroWebhookReceived::class, function (XeroWebhookReceived $event) {
    // Xero sends events for every organisation connected to your Xero app,
    // including ones this application has no connection for. Skip those.
    if ($event->connection() === null) {
        return;
    }

    if ($event->event->isInvoice()) {
        SyncXeroInvoice::dispatch($event->resourceId(), $event->tenantId());
    }
});
```

**Organisations you have not connected.** Xero delivers events for every organisation connected to the
Xero app — which can include one connected from another environment, or one forgotten here but never
disconnected at Xero. `$event->connection()` returns the stored connection for the event's organisation,
or `null` when there is none; it is a query on every call. An invalidated connection is returned too, so
check `isUsable()` before calling Xero with it.

To have the package skip those events instead, set `XERO_WEBHOOK_UNKNOWN_TENANTS=ignore`. An event for
an organisation with no stored connection is then dropped before any listener runs — neither dispatched
nor recorded — and a delivery that dropped any logs one info line. App Store subscription events are
never dropped, an invalidated connection still counts as known, and an event whose lookup fails is
dispatched rather than lost. The setting is read by the queue worker, so restart the workers after
changing it. The default, `dispatch`, leaves the decision to each listener.

The controller verifies the HMAC signature, queues one job and returns. Listeners run in that job, not
in the request, because Xero requires a 2xx within **5 seconds** and disables a subscription after 24
hours of failures. On the `sync` queue driver — `QUEUE_CONNECTION=sync`, or
`XERO_WEBHOOK_QUEUE_CONNECTION=sync` — the job runs inside the request instead, so give webhooks a real
queue. A retry of a delivery whose job is still queued or running is answered 200 without being queued
again; a retry that arrives before the first delivery is confirmed queued is answered 503, so Xero
retries it later — at worst a later duplicate, never a loss. That rests on a lock, and a cache entry
beside it, in the cache store `XERO_LOCK_STORE` names, or the default store, so across servers it holds
only when that store is shared.

> **Listeners must be idempotent.** Xero stores events for up to 31 days and replays them in order after
> an outage, so the same event can arrive more than once.

The webhook route carries no session, no CSRF and no cookies — any cookie in the response fails Xero's
intent-to-receive check.

---

## Error handling

All exceptions extend `XeroBridgeException`, so `catch (XeroBridgeException $e)` always works. Catch a
subclass when you want to react differently:

| Exception | What to do |
|---|---|
| `XeroConfigurationException` | Fix `.env`. Nothing is wrong with the connection. |
| `XeroConnectionNotFoundException` | Nobody has connected that organisation yet. |
| `XeroReauthorizationRequiredException` | A human must reconnect. The message carries the URL. |
| `XeroScopeException` | Widen `XERO_SCOPES` and re-consent. Refreshing will not help. |
| `XeroValidationException` | Fix the payload — see `validationErrors()`. |
| `XeroRateLimitException` | `release($e->retryAfter())`. |
| `XeroServiceUnavailableException` | Xero is down. Retry later. |
| `XeroWriteAlreadyClaimedException` | The write ledger already holds this write. Stop: never retry it. `xeroId()` is the id it produced, or `isPending()` is true while its outcome is unknown. |
| `XeroWriteLedgerUnavailableException` | `XERO_WRITES_STRICT` refused a write the ledger could not record. Nothing was sent: retry once the table is migrated and the database is reachable. |

`$e->context()` returns a log-safe array. No exception message or context ever contains a token or your
client secret.

Every `XeroWriteAlreadyClaimedException` is preceded by a `XeroWriteBlocked` event, for monitoring: a
pending one whose `claimedAt` is more than a few minutes old is a stuck claim, and worth an alert.

---

## Testing your integration

Your test suite should never reach Xero. Stop it trying, give it a connection to use, and fake the
endpoints your code calls:

```php
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Models\XeroConnection;

Http::preventStrayRequests();

// A connection that has not expired, so no token refresh is attempted.
XeroConnection::create([
    'key' => 'default',
    'tenant_id' => 'test-tenant-id',
    'access_token' => 'test-access-token',
    'refresh_token' => 'test-refresh-token',
    'expires_at' => now()->addMinutes(30),
    'scopes' => 'offline_access accounting.invoices accounting.contacts accounting.settings',
]);

Http::fake([
    'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
        'Invoices' => [['InvoiceID' => 'test-id', 'InvoiceNumber' => 'INV-0001']],
    ]),
]);
```

Keep the trailing `*`: without it the pattern matches only the bare `/Invoices` URL, not
`find('INV-0001')` or a filtered list. The tokens are encrypted, so the test environment needs an
`APP_KEY`. [Testing a consuming application](docs/06-recipes.md#6-testing-a-consuming-application) is the
full guide — contacts, emailed invoices, rate limits, and what to assert.

---

## Versioning

[Semantic Versioning](https://semver.org). The public API is the `XeroBridge` facade and everything
reachable from it, the config keys, the published migrations' schemas, the command signatures, the
exception hierarchy and the dispatched events. Since 1.5.0 that includes
`Peoplelogy\XeroBridge\OAuth\Actor` (the value `XeroConnected::$actor` carries),
`Peoplelogy\XeroBridge\Events\XeroWriteBlocked`,
`Peoplelogy\XeroBridge\Exceptions\XeroWriteLedgerUnavailableException` and
`XeroWebhookReceived::connection()`. Anything else under `src/Support/` is internal; the classes there
that these docs tell you to use are public too: `XeroDate`, `ConnectionDefaults` (what
`XeroBridge::defaults()` returns), `BatchResult` (what `createMany()` returns), `InvoiceTransitions`,
`IdempotencyKey` and `Scopes`.

Beyond those, the classes and members these docs tell you to use directly are public API too:

- `Webhooks\WebhookSignature` — `compute()`, `isValid()` and `HEADER`, for signing a test delivery — and
  `Webhooks\WebhookEvent` and `WebhookEnvelope`, which `XeroWebhookReceived` hands you;
- `Jobs\ProcessXeroWebhook` — constructing it with a decoded payload and calling `handle()`, to run your
  listeners in a test;
- `Capture\ApiCallRecorder::forOwner()`;
- the models, as the supported way to read the package's tables: `Models\XeroConnection`,
  `Models\XeroApiCall` with its `forOwner()`, `failed()`, `logicalCall()` and `channel()` scopes,
  `Models\XeroWebhookEvent`, and `MyInvois\Models\MyInvoisValidation` with `isStale()`;
- `Contracts\ConnectionRepository`, for storing connections your own way;
- the MyInvois module's `MyInvois\Facades\MyInvois`, `MyInvoisClient`, `IdType` and `MyInvoisException`,
  and `MyInvois\MyInvoisAudit` — `forgetOwner()`, `forgetSubject()` and `CURRENT_RULES_VERSION`.

Adding a Laravel major is a minor release; dropping one is a major.

---

## Contributing

```bash
composer test       # Pest
composer analyse    # PHPStan
composer format     # Pint
composer serve      # boot the workbench app
```

See [CONTRIBUTING.md](CONTRIBUTING.md) — in particular, tests must be written against the Pest 3 API,
because the CI matrix resolves Pest 3, 4 and 5 depending on the leg.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

Never commit credentials. Tokens are encrypted at rest with your `APP_KEY`. If a Xero client secret is
exposed, rotate it immediately at developer.xero.com and report it to the maintainers.

## Licence

Proprietary — © Peoplelogy Group, licensed for use by Peoplelogy Group and its applications. The source
is publicly visible so the group's applications can install it without credentials; that visibility
grants no licence. See [LICENSE.md](LICENSE.md).
