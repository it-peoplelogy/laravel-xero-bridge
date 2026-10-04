# Commands, errors and token lifecycle

Everything in this document is about operating the package rather than calling the Xero API: the four
Artisan commands, what keeps a connection alive, and what to catch when something fails.

Three facts drive the whole design, and they are worth reading before anything else:

1. A Xero **access token lasts 30 minutes**.
2. A Xero **refresh token rotates** — using one invalidates it and returns a new one that must be saved.
   Two processes refreshing the same connection concurrently each invalidate the other's token.
3. A refresh token that is never used **expires after 60 days**.

---

## Part 1 — Artisan commands

All four commands are registered by the service provider; there is nothing to wire up.

| Command | What it is for |
|---|---|
| `xero-bridge:install` | Once, when adding the package: publish the config and migrations, then print what to configure. |
| `xero-bridge:status` | Monitoring: connection health, the package's own tables, the lock store. Never calls Xero. |
| `xero-bridge:refresh-tokens` | Scheduled hourly: keeps every connection alive. |
| `xero-bridge:prune` | Scheduled daily: trims the package's optional tables. |

### `xero-bridge:install`

Publishes `config/xero-bridge.php` and the package's Xero migrations, then prints the `.env` keys, the
redirect URI and connect URL, what the test console will do in this environment, and the webhook URL —
or, with no `XERO_WEBHOOK_KEY`, that webhooks are off and how to turn them on.

```text
php artisan xero-bridge:install [--force]
```

| Option | Meaning |
|---|---|
| `--force` | Overwrite `config/xero-bridge.php` and the published migrations if they already exist, your own edits to them included. Without it, `vendor:publish` skips files that are present. Never applied to the migrations while a migration of your own has the name of one of the four (see the notes below); the config is still overwritten. |

The migrations are the four under the `xero-bridge-migrations` tag — connections, webhook events, the write
ledger and API capture. The MyInvois config and migration have tags of their own and are never published
here; see [MyInvois TIN validation](08-myinvois-tin-validation.md).

**Sample output** — the shipped defaults, with `APP_URL=https://app.example.com`

```text
   INFO  Installing Xero Bridge.

  Published config/xero-bridge.php ...................................... DONE
  Published the migrations .............................................. DONE

Add these to your .env file:

  XERO_CLIENT_ID=              required - from your Xero app
  XERO_CLIENT_SECRET=          required - shown once, at creation
  XERO_REDIRECT_URI=           required - must match the app exactly
  XERO_WEBHOOK_KEY=            optional - only if you use webhooks
  XERO_SCOPES=                 optional - must include offline_access
  XERO_ACCOUNT_CODE=           required to invoice - differs per organisation
  XERO_TAX_TYPE=               optional - leave unset for per-line tax
  XERO_CURRENCY=               optional - defaults to MYR
  XERO_LOCK_STORE=             recommended - redis/memcached/database

Then:
  1. php artisan migrate
  2. Create an app at https://developer.xero.com/myapps
  3. Register this redirect URI on it, exactly:
     https://app.example.com/xero/callback
  4. Visit https://app.example.com/xero/connect/default to connect an organisation

   WARN  Any signed-in user can connect an organisation, or repoint an existing connection at one of
   their own: the connect route is behind web, auth only. Narrow it with XERO_ROUTES_MIDDLEWARE, e.g.
   XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero".

Test console: off (XERO_CONSOLE_ENABLED is not set)
  Turn it on with XERO_CONSOLE_ENABLED=true in the .env of the environment that should have it.
  It would be served at https://app.example.com/xero/console, behind: web, auth

Webhooks are off: no XERO_WEBHOOK_KEY is set, so no webhook route is served. An application that only calls Xero needs nothing more.
  To receive webhooks, register https://app.example.com/xero/webhook in your Xero app's Webhooks tab, put the key Xero shows into XERO_WEBHOOK_KEY, rebuild config:cache and route:cache, then press Send "Intent to receive".

   WARN  Schedule xero-bridge:refresh-tokens with ->withoutOverlapping()->onOneServer(). Xero rotates
   refresh tokens, so two concurrent refreshes invalidate each other.
```

With `XERO_CONSOLE_ENABLED=true`, the console block reads instead:

```text
Test console: ON (XERO_CONSOLE_ENABLED=true)
  https://app.example.com/xero/console
  Behind: web, auth
  Writes into Xero: a Demo Company only

   WARN  Any authenticated user can open it, read the connected organisation's invoices and contacts, and
   forget its connection. Narrow it with XERO_CONSOLE_MIDDLEWARE, e.g.
   XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero".
```

With `XERO_WEBHOOK_KEY` set, the webhook block reads instead:

```text
Webhook URL (paste into the Xero app's Webhooks tab):
  https://app.example.com/xero/webhook
```

With `APP_URL=http://app.test` and no `XERO_REDIRECT_URI` — a local host that is not `localhost` — a
warning appears after step 4, and, once `XERO_WEBHOOK_KEY` is set, another after the webhook URL:

```text
  3. Register this redirect URI on it, exactly:
     http://app.test/xero/callback
  4. Visit http://app.test/xero/connect/default to connect an organisation

   WARN  Xero requires an https redirect URI; [http://app.test/xero/callback] is not. The only exception
   is http://localhost for local testing. Step 3 shows the package's own callback URL in its place.

   …

Webhook URL (paste into the Xero app's Webhooks tab):
  http://app.test/xero/webhook

   WARN  Xero only delivers webhooks to https on port 443, so this URL will not work as-is. Set APP_URL
   to your public https address.
```

**Exit codes**

| Code | Meaning |
|---|---|
| `0` | Always, warnings included. The command publishes and prints; it reaches no network. |

**Notes / gotchas**

- The redirect URI printed at step 3 comes from `XERO_REDIRECT_URI`, falling back to the package's own
  callback route. It must be registered on the Xero app **character for character**, including any
  trailing slash. When it cannot be used, step 3 prints the package's callback URL instead —
  `routes.prefix` + `/callback`, so it follows `XERO_ROUTES_PREFIX` — and a warning after step 4 gives the
  reason, ending "Step 3 shows the package's own callback URL in its place." There are three: an `http`
  URI on any host but `localhost`; `http://127.0.0.1`, which Xero rejects explicitly (use
  `http://localhost`); and nothing to resolve at all, because the routes are disabled and no
  `XERO_REDIRECT_URI` is set.
- The connect-route warning appears whenever the routes are enabled and `XERO_ROUTES_MIDDLEWARE` is exactly
  the shipped `web,auth` — so on every default install. Under that stack any signed-in user can open
  `/xero/connect/{key}`, and with the default `XERO_ON_KEY_CONFLICT=replace` that repoints an existing key
  at whatever organisation they authorise. A louder one appears when it is `web` alone: "Anyone, signed in
  or not, can connect an organisation, ...". Narrow it to your own admin gate.
- **A migration of your own with a package migration's name.** `vendor:publish` maps each package
  migration onto the first file in `database/migrations` whose name ends with that migration's name, and
  skips it as already published. When that file is your own — `make:migration create_xero_connections_table`
  gives exactly such a name — the package's migration is never published, and `--existing` or `--force`
  would overwrite your file with it. Before publishing, the command looks at the first such file for each
  of the five package migrations, MyInvois's included, and warns for each one that is not a copy of the
  package's (a copy reads the config key that names its table, such as `xero-bridge.database.table`):

  ```text
     WARN  database/migrations/2023_05_01_000000_create_xero_connections_table.php is a migration of your
     own with the name of the package's create_xero_connections_table migration, so vendor:publish
     --tag=xero-bridge-migrations takes it for the package's: it does not publish the package's own, and
     with --existing or --force it would overwrite your file. To publish the package's, rename your
     migration -- and its row in the migrations table, if it has run -- and publish again.
  ```

  With `--force`, when such a file shadows one of the four Xero migrations, `--force` is not passed to
  the migrations publish — none is overwritten, yours included — and the warning ends "So --force was not
  applied to the migrations: none was overwritten, yours included." The config is still forced.
- The console block reports the gate the console itself applies, never the route list: on only for
  `XERO_CONSOLE_ENABLED=true`, in any environment. The reason reads `XERO_CONSOLE_ENABLED is not set`
  (unset or empty), `XERO_CONSOLE_ENABLED=true` (`true`, `1`, `on` or `yes`), or `XERO_CONSOLE_ENABLED=false`
  (anything else). When it is on, the warning appears for exactly `web,auth`, a louder one for `web`
  alone (no authentication at all), and another for no middleware at all ("no CSRF protection"); any
  other stack is taken to be your own decision. The connect-route warning follows the same two cases. A route cache
  built while the console was off still has no console route, so the page 404s until
  `php artisan route:clear` even though this says `ON`. See [The test console](07-test-console.md).
- The webhook block takes one of three forms, by the same rule that decides whether the route is
  registered:
  - **`XERO_WEBHOOK_KEY` set** — the URL to paste into the Xero app. It is the registered route's
    own URL; when that route is not registered — a `route:cache` built before the key was set — it is
    built by the same rule the route uses: `XERO_WEBHOOK_PREFIX`, or `routes.prefix` when that is unset
    or empty, plus `webhooks.path`, so it moves with `XERO_ROUTES_PREFIX` unless `XERO_WEBHOOK_PREFIX`
    is set. This is the only form that warns about a URL that is not https.
  - **No `XERO_WEBHOOK_KEY`**, the shipped default — no webhook route is served, and the block says so:
    "Webhooks are off: no XERO_WEBHOOK_KEY is set, so no webhook route is served. An application that
    only calls Xero needs nothing more." The indented line after it says how to turn webhooks on: "To
    receive webhooks, register {URL} in your Xero app's Webhooks tab, put the key Xero shows into
    XERO_WEBHOOK_KEY, rebuild config:cache and route:cache, then press Send "Intent to receive"." — the
    URL built by that same rule, printed as it is. Neither line is a warning: an application that only
    calls Xero has nothing to do.
  - **`XERO_WEBHOOKS_ENABLED=false`**, whatever the key — one line:
    "Webhooks are disabled (XERO_WEBHOOKS_ENABLED=false), so there is no webhook URL to register."
- Run again, with or without `--force`, it publishes any migration of the tag you have never published,
  under a fresh timestamp: a new table to migrate. `--force` also rewrites the ones already published in
  place, under their existing filenames, so `migrate` does not run those again.
- Nothing here writes to `.env`. The keys are printed for you to copy.

---

### `xero-bridge:status`

Reports connection health, configuration gaps, the state of the package's own tables and the cache store
that holds its locks. It never calls Xero, so it is safe to run from monitoring on a tight loop.

```text
php artisan xero-bridge:status [--json] [--strict]
```

| Option | Meaning |
|---|---|
| `--json` | Emit machine-readable JSON instead of the report. Nothing human-readable is printed, but everything the report says is in the JSON — warnings, notes and pre-flight checks included — and the exit code is the same. |
| `--strict` | Treat warnings and a lock pre-flight that did not pass as failures, returning `1`. What it counts is listed below. |

**What it prints, in order**

1. Missing configuration, as an ERROR block: `XERO_CLIENT_ID`, `XERO_CLIENT_SECRET`, or `offline_access`
   in `XERO_SCOPES`.
2. The connections: the table, with an ERROR for each one that must be authorised again; or "No Xero
   organisations are connected." and the connect URL; or — when the connections table itself is missing,
   cannot be read, or is not the package's own — that problem, as an ERROR in place of the table. The
   Token column reads `valid`, `expired` (the next call refreshes it), `reconnect`, or `unreadable` for
   tokens the current `APP_KEY` cannot decrypt.

   The table checked is the configured model's own — `xero-bridge.model`'s `getTable()` and
   `getConnectionName()` — and only while the package's `EloquentConnectionRepository` stores the
   connections. A host that bound its own `ConnectionRepository` gets no package-table check: its
   connections are read through its repository, as they always were. Whatever repository is bound, a read
   that throws is printed as an ERROR in place of the table, and exits `1`: "Could not read the stored
   connections: <the exception's message>. Until they can be read, the state of every connection is
   unknown."
3. Any pre-flight check that did not pass: the same two the [test console](07-test-console.md#pre-flight)
   runs. The consent-flow check is left out when step 1 has already said why the flow cannot start.
4. Warnings (WARN) — the list is under `--strict` below.
5. Notes (INFO): choices made explicitly, which are never counted.
6. One INFO line on the test console: whether it is on and why — and, when it is on, where it is served
   and what it sits behind.

Steps 3 to 6 print whatever the state of the connections, so a run that fails always says why. Long lines
wrap at the terminal's width: script against `--json`, not the text.

**Sample output — healthy**

```text
+---------+--------------+-------+---------------------------+----------+
| Key     | Organisation | Token | Expires                   | Failures |
+---------+--------------+-------+---------------------------+----------+
| default | Acme Sdn Bhd | valid | 2026-01-15T09:30:00+00:00 | 0        |
+---------+--------------+-------+---------------------------+----------+

   INFO  Test console: off (XERO_CONSOLE_ENABLED is not set).
```

**Sample output — one connection needs re-authorising**

```text
+---------+-------------------+-----------+---------------------------+----------+
| Key     | Organisation      | Token     | Expires                   | Failures |
+---------+-------------------+-----------+---------------------------+----------+
| acme    | Acme Holdings Bhd | reconnect | 2026-01-15T09:30:00+00:00 | 0        |
| default | Acme Sdn Bhd      | valid     | 2026-01-15T09:30:00+00:00 | 0        |
+---------+-------------------+-----------+---------------------------+----------+

   ERROR  Connection [acme] must be authorised again (invalid_grant). Go to
   https://app.example.com/xero/connect/acme.

   INFO  Test console: off (XERO_CONSOLE_ENABLED is not set).
```

**Sample output — configuration incomplete**

```text
   ERROR  Configuration is incomplete:

  missing client_secret (XERO_CLIENT_SECRET)

   WARN  No Xero organisations are connected.

  Connect one at https://app.example.com/xero/connect/default

   INFO  Test console: off (XERO_CONSOLE_ENABLED is not set).
```

**Sample output — the connections table is missing**

```text
   ERROR  The connections table [xero_connections] does not exist, so nothing can be connected or
   refreshed. Run php artisan migrate -- first php artisan vendor:publish --tag=xero-bridge-migrations if
   the package's migrations are not published yet.

   INFO  Test console: off (XERO_CONSOLE_ENABLED is not set).
```

**Sample output — `--json`**

```json
{
    "missing_config": [],
    "connections": [
        {
            "key": "default",
            "organisation": "Acme Sdn Bhd",
            "tenant_id": "e1a4b2c6-0000-0000-0000-9f3d7c2b1a58",
            "tenant_type": "ORGANISATION",
            "expires_at": "2026-01-15T09:30:00+00:00",
            "expires_in": 1800,
            "expired": false,
            "usable": true,
            "needs_reauthorisation": false,
            "invalidated_reason": null,
            "failure_count": 0,
            "last_refreshed_at": "2026-01-15T09:00:00+00:00",
            "last_failure_at": null,
            "scopes": [
                "offline_access",
                "accounting.invoices",
                "accounting.contacts",
                "accounting.settings.read"
            ],
            "connect_url": "https://app.example.com/xero/connect/default",
            "tokens_readable": true
        }
    ],
    "warnings": [],
    "notes": [],
    "preflight": {
        "connect": {
            "label": "Consent flow can start",
            "ok": true
        },
        "lock": {
            "label": "Token-refresh lock is usable",
            "ok": true
        }
    },
    "table_problems": [],
    "write_ledger": {
        "enabled": false,
        "strict": false,
        "table": "xero_write_records",
        "table_present": null,
        "stuck": null
    },
    "console": {
        "enabled": false,
        "reason": "XERO_CONSOLE_ENABLED is not set",
        "middleware": [
            "web",
            "auth"
        ],
        "url": null
    }
}
```

`missing_config` and `connections` come first, as they always have. Every later key is appended after
them, so a parser written against an earlier release still finds what it reads.

| Key | Shape | Notes |
|---|---|---|
| `missing_config` | `[]`, or an object of config key → environment variable | An array when nothing is missing, an object when something is. |
| `connections` | list of objects | One per stored connection, with no token in it. `[]` when nothing is connected — and also when the connections table is unusable, so read it together with `table_problems` and the exit code. |
| `warnings` | list of strings | The warnings `--strict` counts, as the report prints them, plus the connections-table problem, which the report prints as an ERROR. Pre-flight results are in `preflight`; "No Xero organisations are connected." is in neither. |
| `notes` | list of strings | Explicit choices worth knowing, such as a `file` lock store that `XERO_LOCK_STORE` names. Never counted. |
| `preflight` | `connect` and `lock`, each `{label, ok}` | A check that did not pass adds `severity` (`warn` or `fail`) and `message`; a `fail` adds `type`, the exception's short class name. `--strict` counts `lock` only. |
| `table_problems` | `[]`, or an object of config key → message | Keyed `database.table`, `writes.table`, `webhooks.dedupe.table` or `capture.table`. Every entry is in `warnings` too. |
| `write_ledger` | `{enabled, strict, table, table_present, stuck}` | `table` carries the connection's prefix. With the ledger off nothing is queried and the last two are `null`. On, `table_present` is `null` when the check itself failed, and `stuck` — claims pending over an hour — is `null` whenever it was not counted. |
| `console` | `{enabled, reason, middleware, url}` | The same gate the console applies. `url` is `null` unless the console route is registered. |

**What `--strict` counts**

Under `--strict`, any of these returns `1`. Without it none of them changes the exit code, except
undecryptable tokens on a connection that is not invalidated.

| Found | Printed as |
|---|---|
| The table of a feature you switched on — `XERO_WRITES_LEDGER`, `XERO_WEBHOOK_DEDUPE` or `XERO_CAPTURE` — exists but is not the package's | WARN naming the physical table, the columns it lacks, what that breaks, and the variable to set |
| The write ledger is on and its table does not exist, or could not be checked | WARN |
| Write claims pending for over an hour | WARN — the same line `xero-bridge:prune` prints |
| A package table the `migrations` table records as created more than once | WARN: rolling back the later batch would drop the live table |
| The lock store is an `array` store, named or the default | WARN: it locks inside one process only |
| The lock store is a `file` store that is only the default — `XERO_LOCK_STORE` unset or blank | WARN: it locks within one server only |
| A connection with recent transient failures, and not invalidated | WARN |
| A connection whose stored tokens the current `APP_KEY` cannot decrypt (`tokens_readable: false`) | WARN: put the old key in `APP_PREVIOUS_KEYS`, or re-authorise. Unless the connection is invalidated, this one also returns `1` without `--strict`: see the exit codes below |
| The lock pre-flight did not pass: a store that supports no locks, the `null` driver, a store that is not a standard cache repository, or one that cannot be resolved or reached | WARN or ERROR, starting "Token-refresh lock is usable:" |

Never counted, even under `--strict`:

- the consent-flow pre-flight. With no `XERO_REDIRECT_URI` this process derives the callback URL from
  `APP_URL`, while the browser derives it from the host it was sent to, so the check can fail here and
  pass where it matters. It is printed, not counted;
- notes. A `file` store that `XERO_LOCK_STORE` names locks every process on one server — right while the
  scheduler, queue workers and web servers share that server — and was chosen explicitly;
- the test-console line;
- a webhook-replay or capture table that is merely missing: a flag with no table is a documented no-op,
  and nothing about it is reported;
- no `XERO_WEBHOOK_KEY`. Without a key no webhook route is served — webhooks are off, which is not a
  fault — so nothing about it is reported. Before 1.6.0 it was a warning;
- MyInvois, which status never reports on;
- "No Xero organisations are connected." Nothing connected is not a failure.

**Exit codes**

| Code | Constant | Meaning for monitoring |
|---|---|---|
| `0` | `Command::SUCCESS` | Every stored connection is usable, and under `--strict` nothing above was found. A token shown as `expired` still counts as usable — the next call refreshes it. |
| `1` | `StatusCommand::EXIT_CONFIG_INCOMPLETE` | The connections table is missing, cannot be read, or is not the package's own; the stored connections could not be read; or `XERO_CLIENT_ID`, `XERO_CLIENT_SECRET` or `offline_access` in `XERO_SCOPES` is missing. Either way nobody can connect and nothing will refresh. Also returned when a connection that is not invalidated holds tokens the current `APP_KEY` cannot decrypt — its Token column reads `unreadable` — because every call through it fails with a `XeroConfigurationException`; the old key in `APP_PREVIOUS_KEYS` fixes every organisation at once. (1.4.3 exited `1` here too, by crashing.) Page whoever owns the deploy. Also returned by `--strict` for anything in its list. |
| `2` | `StatusCommand::EXIT_NEEDS_REAUTH` | At least one connection is marked invalidated. **A human must visit the connect URL.** No amount of retrying fixes this. |

Checked in that order: the connections table and the configuration first (`1`), then connections that
need re-authorising (`2`), then `--strict` (`1`). So a run that is both misconfigured and holding a dead
connection returns `1`, not `2` — and a dead connection returns `2` even under `--strict`.

**Notes / gotchas**

- **This command never calls Xero.** It reads config, the package's tables — whether each one it reports on
  exists and has the package's columns, the stored connections and, with the ledger on, a count of stuck
  claims — and the `migrations` table, plus the files in `database/migrations` of the package migrations it
  finds recorded there, to tell the package's from a migration of your own. It also takes one cache lock, `xero-bridge:preflight-probe`, and
  releases it at once, to prove the lock store works. That is why it is safe on a one-minute monitoring
  schedule. There is a test asserting no HTTP request escapes.
- `missing_config` and `table_problems` are a JSON **array** `[]` when empty, and a JSON **object** when
  not. Parse defensively:

  ```php
  <?php

  $status = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);

  $missing = (array) ($status['missing_config'] ?? []);

  if ($missing !== []) {
      // Keys are config paths, values are the env var names to set.
      foreach ($missing as $configKey => $envKey) {
          fwrite(STDERR, "xero-bridge: {$configKey} is not set ({$envKey})\n");
      }
  }

  // Keys are config keys such as 'writes.table'; values say which table and how to fix it.
  foreach ((array) ($status['table_problems'] ?? []) as $configKey => $problem) {
      fwrite(STDERR, "xero-bridge: {$problem}\n");
  }
  ```

- A table problem names the physical table, the connection's prefix included, and the variable that names
  it. When the package's migration for that table is already recorded as run, the advice changes. First:
  if you changed that variable or `XERO_DB_CONNECTION` after migrating, change it back — the package's
  table is still where the migration created it, and recreating it would strand the real one. Otherwise
  `migrate` will not run a recorded migration again, so it tells you to delete that row from the
  `migrations` table first — and, for a table that is not the package's, never to roll the migration back,
  which would drop the table that is there.
- A recorded migration counts as the package's only when its file in `database/migrations` is a copy of
  the package's — it reads the config key that names its table, such as `xero-bridge.database.table`,
  which every published copy does. A migration of your own with the same name —
  `2023_05_01_000000_create_xero_connections_table`, say — is ignored: status never names it, never tells
  you to delete its row, and never counts it as a duplicate, and the advice is the plain one ("Set
  XERO_DB_TABLE ... and run php artisan migrate.", or "Run php artisan migrate -- first ..."). When the
  recorded migration's file cannot be found, the advice is conditional and starts by saying so: "<name> is
  recorded as run, but its file is not in database/migrations, so whether it is the migration published
  from this package cannot be checked from here. If it is a migration of your own, leave its row alone
  ...; what follows applies only if it is the package's." A duplicate warning naming such a migration
  ends "never delete a migration of your own, or its row." Status does not say that a migration of your
  own with the package's name also stops `vendor:publish` publishing the package's: until you rename your
  file, the "first php artisan vendor:publish" step publishes nothing.
  [`xero-bridge:install`](#xero-bridgeinstall) warns about that.
- A table recorded as created by two migrations comes from two environments publishing the same package
  migration at different moments, under different filenames, and both copies running — the second as a
  no-op over the first one's table. It is harmless until someone rolls back: the later copy's rollback
  drops the live table. Delete the **later** file and its row in the `migrations` table, keep the first,
  and never roll back a batch that still contains it.
- `expires_in` is signed. A token that expired ten minutes ago reports `-600`.
- Tokens never appear in the output, in either format.
- The console line is information, never a warning, and uses `xero-bridge:install`'s words:
  `Test console: off (XERO_CONSOLE_ENABLED is not set).`, `Test console: off (XERO_CONSOLE_ENABLED=false).`,
  or `Test console: ON (XERO_CONSOLE_ENABLED=true) at https://app.example.com/xero/console, behind web, auth.`
  The URL appears only when the console route is registered, and an empty middleware list reads "with no
  middleware in front of it".
- Invalid UTF-8 inside a message — a database error, say — is replaced with U+FFFD in `--json`, rather than
  emptying the output.

---

### `xero-bridge:refresh-tokens`

Refreshes every stored connection whose access token is close to expiry. This is the command you schedule.

```text
php artisan xero-bridge:refresh-tokens [--connection=KEY] [--window=1800] [--force]
```

| Option | Default | Meaning |
|---|---|---|
| `--connection=` | all connections | Only refresh this connection key. An unknown key is an error, not a no-op. |
| `--window=` | `1800` | Refresh any token expiring within this many seconds. The default is a full access-token lifetime, so an hourly schedule always has something to do. |
| `--force` | off | Refresh even when the token is still fresh. Burns a rotation; use it to prove the credentials work, not on a schedule. |

**Sample output — mixed run**

```text
  acme ........................................................... still fresh
  default (Acme Sdn Bhd) ............................ refreshed until 09:30:00
```

**Sample output — transient failure (exit 1)**

```text
   WARN  [default] Could not reach Xero to refresh the token for connection [default]: Xero returned
   HTTP 503. The stored tokens are unchanged and this can safely be retried.
```

**Sample output — re-authorisation required (exit 2)**

```text
   ERROR  [default] The Xero connection [default] must be authorised again (refresh token expired).
   Re-authorise at https://app.example.com/xero/connect/default.
```

**Sample output — another process is already refreshing (exit 0)**

```text
  default ................................................... skipped (locked)
```

**Sample output — nothing to do**

```text
   INFO  No Xero connections to refresh.
```

**Exit codes**

| Code | Constant | Meaning for monitoring |
|---|---|---|
| `0` | `Command::SUCCESS` | Every connection was refreshed, was already fresh, or was skipped because another process holds the lock. All three are healthy. |
| `1` | `RefreshTokensCommand::EXIT_TRANSIENT_FAILURE` | At least one connection could not be refreshed **and nothing was changed**. Retry later. Alert only if it persists across several runs. Also returned when `--connection` names a key that does not exist. |
| `2` | `RefreshTokensCommand::EXIT_NEEDS_REAUTH` | At least one connection needs a human. Alert immediately. |

The command refreshes every connection before returning, and the exit code is the **highest** code any one
connection produced. A run where one connection 503s and another is invalidated returns `2`.

**Notes / gotchas**

- Exit `1` is overloaded: it covers a transient failure, a `--connection` key that does not exist, and —
  through the command's catch-all — any other error, including the `XeroConfigurationException` raised when
  Xero rejects the client credentials, which no amount of retrying fixes. Distinguish them by the output,
  or avoid `--connection` in automation.
- "skipped (locked)" is **not** a failure. It means a concurrent run already holds the per-connection lock
  and is doing the work. Deliberately exit `0`.
- An already-invalidated connection short-circuits: the command reports it and returns `2` without any
  network call at all. A five-minute schedule over a dead connection therefore does not hammer
  `identity.xero.com`.
- `--window` is passed all the way through to the lock re-check. Without that, the re-check inside the lock
  would apply the 60-second leeway instead, decide a token expiring in five minutes needed nothing doing,
  and the scheduled job would quietly never refresh anything.

---

### `xero-bridge:prune`

Trims the package's optional tables — webhook replay records, write-ledger entries, captured API calls and
MyInvois verdicts — in one command, so you schedule one thing whichever features you switched on. What each
table keeps, and why, is in [Persistence → Pruning](09-persistence.md#pruning).

```text
php artisan xero-bridge:prune [--dry-run]
```

| Option | Meaning |
|---|---|
| `--dry-run` | Report what would be deleted, and delete nothing. |
| `-v` | Artisan's standard verbosity flag. Also lists each table that is not migrated, with the publish tag that creates it. |

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('xero-bridge:prune')->daily();
```

**Sample output**

```text
  webhook replay records .................................... nothing to prune
  write ledger entries ............................................. deleted 3
  captured API calls ........................................ nothing to prune
```

**Sample output — `--dry-run -v`, with the MyInvois table never migrated**

```text
   INFO  Dry run: nothing will be deleted.

  webhook replay records .................................... nothing to prune
  write ledger entries ........................................ would delete 3
  captured API calls ........................................ nothing to prune
  MyInvois verdicts ................. not migrated (--tag=myinvois-migrations)
```

**Sample output — a stuck write claim (exit 1)**

```text
  webhook replay records .................................... nothing to prune
  write ledger entries ...................................... nothing to prune
  captured API calls ........................................ nothing to prune

   WARN  1 write claim(s) have been pending for over an hour. Each means something was sent to Xero and
   the outcome was never recorded, so further writes for those records are BLOCKED. Check Xero, then
   resolve the rows by hand -- nothing will re-send on its own, because re-sending could duplicate a
   record that already exists.
```

**Exit codes**

| Code | Constant | Meaning for monitoring |
|---|---|---|
| `0` | `Command::SUCCESS` | Pruned — or, with `--dry-run`, counted — and no write claim is stuck. |
| `1` | `PruneCommand::EXIT_STUCK_CLAIMS` | At least one write claim has been pending for over an hour. Each one blocks further writes for its record until a person checks Xero and resolves the row by hand. |

**Notes / gotchas**

- A table that does not exist is skipped in silence, on screen and in the log, so a host that never
  switched a feature on is not told off by a scheduled command. Only `-v` names it.
- A table whose check throws — the database cannot be reached, say — prints an ERROR naming it, and the run
  carries on. That does not change the exit code, so do not rely on prune to notice a database outage.
- **A table that is not the package's is never pruned.** Before it deletes anything, prune checks that the
  table under the package's name has the package's own columns — `dedupe_key`, `delivery_count`,
  `first_seen_at` for webhook replay records; `claim_key`, `connection_key`, `claimed_at` for the write
  ledger; `logical_call_id`, `channel`, `created_at` for captured API calls; `subject_hash`, `tin_last4`,
  `last_checked_at` for MyInvois verdicts. A table lacking any of them — your own `xero_api_calls`, say — is
  skipped with an ERROR, and the run carries on with the exit code unchanged:

  ```text
     ERROR  The table [xero_api_calls] is not the package's captured API calls table (it has no
     logical_call_id, channel, created_at columns), so nothing in it was pruned. If it is yours, set
     XERO_CAPTURE_TABLE to an unused name.
  ```

  From 1.4.0 to 1.4.3 prune deleted the rows past retention from any table with a package table name,
  whether or not the feature was on, because the retention query filters on age alone.
- Pending write claims are never pruned, at any age; that is what exit `1` is for. `xero-bridge:status`
  reports the same stuck claims as a warning while `XERO_WRITES_LEDGER` is on.
- Rows are deleted 1,000 at a time, so a first prune over a year of rows does not lock a production table
  in one statement.

---

## Part 2 — The token lifecycle

### What Xero guarantees

| Fact | Value | Consequence |
|---|---|---|
| Access token lifetime | 30 minutes | Every long-running process must be able to refresh. |
| Refresh token rotation | On every use | The new refresh token **must** be persisted or the connection is lost. |
| Rotation grace window | ~30 minutes | If you rotate but fail to save, the previous refresh token still works for about half an hour. |
| Idle refresh-token expiry | 60 days | A connection nobody touches for two months dies. This is why you schedule the command. |
| `offline_access` scope | Mandatory | Without it Xero issues no refresh token at all and the connection dies 30 minutes after it is created. |

### How a refresh is triggered

There are three entry points, and you normally only use the first two implicitly:

1. **Before any API call.** `TokenManager::valid()` refreshes when the token expires within
   `tokens.refresh_leeway` (`XERO_REFRESH_LEEWAY`, default **60** seconds). The leeway exists so a token
   with eight seconds left is not handed to a request that takes ten.
2. **After an unexpected 401.** `XeroHttpClient` refreshes once and replays the request exactly once —
   straight-line code, not a loop. A second 401 throws `XeroAuthenticationException`. The guard is the
   specific access token that failed, not a boolean, so a thundering herd of 401s produces one rotation
   rather than one per caller.
3. **On a schedule**, via `xero-bridge:refresh-tokens`.

An insufficient-scope 401 is detected **before** the refresh path (Xero sends
`WWW-Authenticate: insufficent_scope` — its own spelling, which the package matches alongside the correct
one) and throws `XeroScopeException` immediately. Refreshing would never fix it, and retrying it in a loop
is how an integration ends up spinning forever.

### The per-connection lock

Every refresh runs inside a cache lock named `xero-bridge:refresh:{connection-key}`. The three timings
interlock deliberately:

```text
tokens.http_timeout (8s)  <  tokens.lock_ttl (10s)  <  tokens.lock_wait (12s)
```

A lock holder can never outlive its lock, and a waiter can never give up before a dead holder's lock has
expired. If the wait times out, the package re-reads the row: if the winner refreshed, its token is
returned; otherwise a `XeroBridgeException` with "Timed out waiting" is thrown. It deliberately does **not**
fall back to an unlocked refresh — that is precisely what rotates a refresh token out from under the
process legitimately holding the lock.

> ### ⚠️ `XERO_LOCK_STORE` must be redis, memcached or database — or `file` on a single server
>
> The lock is only as good as the store behind it. An `array` store locks **inside one process** only, so
> two PHP-FPM workers or two queue workers can refresh the same connection simultaneously and invalidate
> each other's refresh token. A `file` store locks every process **on one server**, but not across
> servers: with the scheduler, queue workers or web servers on more than one, the same thing happens.
>
> ```dotenv
> XERO_LOCK_STORE=redis
> ```
>
> Unset or blank, it means the default cache store. If everything genuinely runs on one server,
> `XERO_LOCK_STORE=file` records that choice, and `xero-bridge:status` reports it as a note rather than a
> warning. The same store also holds the webhook job's uniqueness lock, which stops a retried delivery
> being queued twice, and a plain cache entry beside it — so a `database` store needs Laravel's `cache`
> table as well as `cache_locks`. See [When Xero retries a delivery](04-webhooks-and-events.md#when-xero-retries-a-delivery).
>
> If the configured store cannot lock at all — it supports no locks, or uses the `null` driver, which
> grants every lock at once — the package logs a warning **once** per process and refreshes unlocked: a
> single-process application is still perfectly usable. It does not fail closed, so that log line and
> `xero-bridge:status` — a lock pre-flight warning, which `--strict` counts — are the only signals you
> get.

### The scheduling recipe

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('xero-bridge:refresh-tokens')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

> ### ⚠️ Both chained methods are mandatory
>
> `withoutOverlapping()` stops a slow run overlapping the next tick **on the same server**.
> `onOneServer()` stops the same tick running on every server behind your load balancer.
>
> Drop either one and you get two concurrent refreshes of the same connection, each invalidating the
> other's rotated refresh token. The cache lock inside `TokenManager` is the second line of defence, not
> the first — and `onOneServer()` itself requires a locking cache store, which is the same requirement as
> above.

Hourly is the right frequency: the window defaults to 1800 seconds, so each run refreshes anything due
within the next half hour, and a connection idle for weeks never approaches the 60-day cliff.

### The guarantee: a transient failure never alters stored tokens

This is the single most important behaviour in the package. Failures are classified into exactly three
buckets, because the correct reaction to each is completely different:

| What happened | Exception | Stored tokens | `invalidated_at` | Event |
|---|---|---|---|---|
| Timeout or connection error reaching `identity.xero.com` | `XeroIdentityUnavailableException` | untouched | untouched | none |
| HTTP 5xx from the identity host | `XeroIdentityUnavailableException` | untouched | untouched | none |
| HTTP 429 from the identity host | `XeroIdentityUnavailableException` | untouched | untouched | none |
| `{"error":"invalid_client"}`, `{"error":"unauthorized_client"}` or a 401 | `XeroConfigurationException` | untouched | untouched | none |
| Rotation succeeded but the database write failed | the underlying `Throwable` | previous pair still stored | untouched | none, plus a `critical` log line |
| **`{"error":"invalid_grant"}`** | `XeroReauthorizationRequiredException` | untouched | **set** | `ConnectionExpired`, once |

Two invariants hold throughout the token lifecycle, and both are pinned by tests:

- **No refresh, however it fails, deletes a connection row.** The most destructive thing a refresh can do
  is set `invalidated_at`, which reconnecting clears. Rows are deleted only by two things a person sets
  off, never by token handling: the test console's *Forget connection*, and a connect under
  `XERO_ON_TENANT_CONFLICT=rekey` with `XERO_ON_KEY_CONFLICT=replace` that moves an organisation onto a key
  holding a different one. Both are Eloquent deletes, so the model's `deleting` and `deleted` events fire.
- **Only a genuine `invalid_grant` is terminal.** A transient failure increments `failure_count` and sets
  `last_failure_at`. Those two columns are the *only* things that change.

`invalid_client` deserves its own row above: it means your application's own credentials are wrong. Marking
every connection as expired for that would demand a re-consent from every user when the actual fix is one
line of `.env`.

### Events to hang alerting on

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;
use Peoplelogy\XeroBridge\Events\TokenRefreshed;

Event::listen(function (ConnectionExpired $event): void {
    Log::critical('A Xero connection needs re-authorising.', [
        'connection' => $event->connection->key,
        'reason' => $event->reason,
        'connect_url' => $event->connectUrl,
    ]);
});

Event::listen(function (TokenRefreshed $event): void {
    Log::info('Xero token rotated.', [
        'connection' => $event->connection->key,
        'expires_at' => $event->expiresAt?->toIso8601String(),
    ]);
});
```

`ConnectionExpired` fires **only on the valid → invalidated transition**, so a nightly cron hitting the same
dead connection cannot spam your listeners. That makes it safe to wire straight to a pager.

It never fires for an organisation that disconnected the app **inside Xero**. Xero sends no webhook for
that; the next call simply fails with a `XeroAuthenticationException`, and nothing marks the connection —
so alert on that exception as well.

### `APP_KEY` rotation

Both tokens use Laravel's `encrypted` cast. Rotate `APP_KEY` and every stored token becomes undecryptable.
The package catches the `DecryptException` and rethrows `XeroConfigurationException` naming `APP_KEY` and
the connect URL, rather than letting a bare decryption error surface from inside Eloquent with nothing
linking it to Xero. Every organisation must reconnect — unless the old key is kept in
`APP_PREVIOUS_KEYS`, which keeps the stored tokens readable. The message names both ways out: "The stored
Xero tokens for connection [default] cannot be decrypted. This normally means APP_KEY changed since they
were saved. Put the old key in APP_PREVIOUS_KEYS, or re-authorise at https://app.example.com/xero/connect/default."
`xero-bridge:status` shows such a connection as `unreadable` in its Token column and exits `1`.

Related, and worth knowing before you add any model observer: do **not** attach an activity-log trait to
`XeroConnection`. The `encrypted` cast protects the tokens at rest, not once the model is hydrated — an
activity log, `toArray()`, a JSON response or `Log::info($model)` all emit the decrypted value. The model
declares both tokens in `$hidden`, which keeps them out of `toArray()` and JSON — but not out of the
payload of a queued listener whose event carries the model; see
[Webhooks and events](04-webhooks-and-events.md).

---

## Part 3 — Exceptions

### The hierarchy

Everything the package throws for a Xero, connection or payload failure extends `XeroBridgeException`,
which extends `RuntimeException`. Subclasses are discriminated by **what the caller must do differently**,
not by which endpoint failed.

```text
RuntimeException
└── XeroBridgeException
    ├── XeroAuthenticationException
    │   ├── XeroReauthorizationRequiredException
    │   └── XeroScopeException
    ├── XeroConfigurationException
    ├── XeroConnectionNotFoundException
    ├── XeroIdentityUnavailableException
    ├── XeroRateLimitException
    ├── XeroRequestException
    ├── XeroServiceUnavailableException
    ├── XeroValidationException
    ├── XeroWriteAlreadyClaimedException
    ├── XeroWriteLedgerUnavailableException
    ├── ConnectionKeyConflictException
    ├── TenantAlreadyConnectedException
    ├── InvalidInvoicePayloadException
    ├── InvalidInvoiceTransitionException
    ├── InvoiceCannotBeVoidedException
    └── UnsafeContactPayloadException
```

`catch (XeroBridgeException $e)` is the coarse catch for everything in that tree, and every accessor below
is available whichever subclass arrives. It is **not** a catch-all for the package: an argument rejected
locally, before any request is built, throws a plain `InvalidArgumentException` — an invoice type that is
not ACCREC or ACCPAY, an order direction that is not ASC or DESC, a page below 1, a payment with no
account, an empty or malformed contact `Name`, an unparseable date. Those are caller bugs, they extend
`LogicException` rather than `RuntimeException`, and they carry none of the accessors below. The MyInvois
module's `MyInvoisException` is deliberately outside the tree too, so a Xero catch block can never swallow
an LHDN fault — see [MyInvois TIN validation](08-myinvois-tin-validation.md#errors).

Two members of the tree want **opposite** treatment, and a catch-all that marks a record failed on any
`XeroBridgeException` should single both out. `XeroWriteAlreadyClaimedException` is the write ledger
*working*: the write was already made, or is in flight, so stop and never retry.
`XeroWriteLedgerUnavailableException` is the ledger unable to answer under `XERO_WRITES_STRICT`: nothing
was sent, so retrying is safe.

> `XeroReauthorizationRequiredException` and `XeroScopeException` both extend `XeroAuthenticationException`.
> If you catch the parent, **catch the two children first** or you will never reach them.

### What each one means and what to do

| Exception | Means | Do |
|---|---|---|
| `XeroConfigurationException` | `.env` or `config/xero-bridge.php` is wrong: a missing client ID or secret, an `http://127.0.0.1` redirect URI (Xero rejects it — use `http://localhost`), a non-https redirect URI, `offline_access` absent from the scopes, credentials rejected by Xero, or undecryptable tokens after an `APP_KEY` rotation. | Fix the configuration and deploy. **Nothing is wrong with the connection** and nothing is retried. Fail the job. |
| `XeroConnectionNotFoundException` | No row is stored under that connection key. | Send someone through the consent flow. The message carries the connect URL. Fail the job. |
| `XeroReauthorizationRequiredException` | Terminal. Xero answered `invalid_grant`, or the connection is already marked invalidated. | **A human must reconnect.** Fail the job and alert. Retrying is pointless; the connection short-circuits without a network call. |
| `XeroScopeException` | Xero refused for insufficient scope (a 401 with `WWW-Authenticate: insufficent_scope`). | Widen `XERO_SCOPES`, redeploy, then re-consent. Scopes are fixed at authorisation time, so doing it the other way round means connecting twice. `grantedScopes()` tells you what the connection actually holds. Refreshing never helps. |
| `XeroAuthenticationException` | A 401 that one token refresh did not fix, or a 403, which is never refreshed or retried — usually the organisation disconnected the app inside Xero, or the tenant is no longer authorised for the app. | Treat as terminal: someone must reconnect. Check the organisation's connected apps. **The package does not detect this:** Xero sends no webhook for a disconnect, nothing marks the connection invalidated, `ConnectionExpired` does not fire and `xero-bridge:status` cannot see it — so alert on this exception itself. |
| `XeroIdentityUnavailableException` | Transient failure talking to `identity.xero.com` — timeout, connection error, 5xx or 429. Stored tokens are untouched. | Retry later. Safe to release the job. |
| `XeroRateLimitException` | HTTP 429 that the package refused to absorb. | `release($e->retryAfter())`. Never sleep inline: a daily-limit `Retry-After` can be hours. `limitProblem()` says which limit. |
| `XeroServiceUnavailableException` | 500/502/503/504, including the plain-text "The Organisation is offline" and "offline for maintenance" bodies. | Retry later. `retryAfter()` defaults to 300 seconds for these. |
| `XeroValidationException` | HTTP 400, `Type: ValidationException`. Xero rejected the payload. | Fix the payload. Read `validationErrors()`. **Do not retry** — it will fail identically. |
| `XeroRequestException` | Any other non-2xx with no more specific subclass: 404, 405, 409, and a 400 carrying no validation errors. Also thrown when Xero returns XML, which means the `Accept: application/json` header went missing. | Inspect `statusCode()` and the message. Usually a bug in the caller. |
| `ConnectionKeyConflictException` | The connection key already points at a **different** Xero organisation and `on_key_conflict` is anything but `replace` — `error`, or a typo, which fails closed. Also thrown under `on_tenant_conflict=rekey` when moving the organisation's row onto this key would displace the different organisation the key holds. Nothing has changed when it is thrown. | Disconnect the old organisation first, or set `XERO_ON_KEY_CONFLICT=replace`. Thrown during the OAuth callback. |
| `TenantAlreadyConnectedException` | The organisation just authorised is already stored under a **different** key and `on_tenant_conflict` is anything but `rekey` — `error` is the default. | Use the existing connection, or disconnect it first. Silently re-keying would break every caller referencing the old key. |
| `XeroWriteAlreadyClaimedException` | The write ledger already holds a claim for this write: the same operation, on the same connection, for the same `for($model)` owner (and the same reference, when one is given). **Not a failure — the duplicate protection working.** Nothing was sent. Confirmed: `xeroId()` is the id of the record Xero already has. Pending (`isPending()`): an earlier attempt sent something and never recorded the outcome. | **Stop; never release or retry it.** Retrying into a claim either loops forever or asks for the very duplicate the ledger prevented. Confirmed: read `xeroId()` and carry on. Pending: leave it for a person to check Xero — the `XeroWriteBlocked` event, dispatched just before, says how old the claim is. See [Persistence](09-persistence.md#write-dedupe). |
| `XeroWriteLedgerUnavailableException` | Only with `XERO_WRITES_STRICT` on, and only for a write named with `for($model)`: the ledger could not record its claim — the table is not migrated, the database is unreachable, or the insert failed for a reason other than a duplicate. The write was **refused and nothing was sent**. `getPrevious()` is the cause. | Retry: it is safe, and right once the table is migrated and the database is reachable. There is no `retryAfter()`, so the job's own backoff applies. Without strict mode the same failure is logged and the write goes out unprotected instead. See [When the ledger itself fails](09-persistence.md#when-the-ledger-itself-fails). |
| `InvalidInvoicePayloadException` | An invoice has no explicit `Type`, or an update carries `LineItems` without `LineItemID` on every line. | Fix the payload — or, to replace every line on purpose, chain `replacingLineItems()` in front of the call: `invoices()->replacingLineItems()->update(...)`. It returns a copy, so on a line of its own it does nothing. The `Type` is never defaulted on purpose: guessing `ACCREC` would, on the one occasion someone meant `ACCPAY`, post a bill as a sale. |
| `InvalidInvoiceTransitionException` | An illegal status change, caught locally. The message names the current status and what is reachable from it. | Fix the caller's logic. |
| `InvoiceCannotBeVoidedException` | The invoice has payments applied. The message names the blocking payment IDs. | Delete the payments with `payments()->delete($paymentId)`, then void. |
| `UnsafeContactPayloadException` | An invoice `Contact` block carries a `ContactID` **plus** other contact fields. Xero would apply them to the contact record itself and delete any `ContactPersons` not included. | Send only `ContactID`, or chain `withContactMutation()` in front of the call — `invoices()->withContactMutation()->create(...)` — to confirm you mean it. It returns a copy, so on a line of its own it does nothing. This is irreversible and nobody notices for weeks, which is why it is refused rather than merely documented. |

### Accessors

Available on every exception in the hierarchy — signatures exactly as in
`src/Exceptions/XeroBridgeException.php`:

```text
public function response(): ?\Illuminate\Http\Client\Response
public function statusCode(): ?int
public function connectionKey(): ?string
public function validationErrors(): array           // list<string>
public function validationErrorsByElement(): array  // array<int, list<string>>
public function xeroType(): ?string
public function xeroErrorNumber(): ?int
public function retryAfter(): ?int
public function context(): array                    // array<string, mixed>
```

Plus a few subclass-specific ones:

```text
// src/Exceptions/XeroRateLimitException.php
public function limitProblem(): ?string   // 'minute' | 'day' | 'concurrent' | 'appminute' | null

// src/Exceptions/XeroScopeException.php
public function grantedScopes(): array    // list<string>

// src/Exceptions/XeroWriteAlreadyClaimedException.php
public function xeroId(): ?string         // the record Xero already has; null while pending
public function isPending(): bool         // true when an earlier attempt's outcome was never recorded
```

| Accessor | Returns | Use it for |
|---|---|---|
| `statusCode()` | The HTTP status, or `null` for a locally raised error such as `InvalidInvoicePayloadException`. | Branching on 404 versus 409 inside `XeroRequestException`. |
| `retryAfter()` | Seconds, where Xero told us or the package supplied a sane default. `null` otherwise. | `release()` on a queued job. |
| `validationErrors()` | Every `ValidationErrors[].Message` flattened and de-duplicated. | Showing a user what to fix. |
| `validationErrorsByElement()` | The same messages keyed by index in `Elements[]`. | Reporting "row 3 of the bulk POST failed because…". |
| `xeroType()` / `xeroErrorNumber()` | Xero's `Type` and `ErrorNumber` from the 400 envelope. | Logging and triage. |
| `connectionKey()` | Which connection was in play. | Multi-organisation apps. |
| `response()` | The raw `Illuminate\Http\Client\Response`. | Last resort. **Never log it** — the request it carries holds the bearer token. |
| `context()` | A filtered array of connection, status, Xero type, error number, retry-after and validation errors. | Passing straight to `Log::error()`. |

### `context()` is the only thing you should log

```php
<?php

use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;

try {
    $invoice = \Peoplelogy\XeroBridge\Facades\XeroBridge::invoices()->create($payload);
} catch (XeroBridgeException $e) {
    Log::error('Xero call failed: '.$e->getMessage(), $e->context());

    throw $e;
}
```

`context()` deliberately excludes the request headers and body, which carry the bearer token. **No exception
message and no `context()` value ever contains an access token, a refresh token or the client secret**, and
there is a test asserting it stays that way. Null and empty entries are stripped, so the array is short.

A representative value:

```php
<?php

// What $e->context() returns for a rejected invoice.
$context = [
    'connection' => 'default',
    'status' => 400,
    'xero_type' => 'ValidationException',
    'xero_error_number' => 10,
    'validation_errors' => [
        'Invoice # must be unique.',
    ],
];
```

### What Xero actually sends

Xero speaks four different error dialects and the package reads all of them, which is why you can branch on
a class rather than on `str_contains($e->getMessage(), ...)`.

**a) HTTP 400 — the validation envelope** → `XeroValidationException`

```json
{
  "ErrorNumber": 10,
  "Type": "ValidationException",
  "Message": "A validation exception occurred",
  "Elements": [
    {
      "AccountID": "00000000-0000-0000-0000-000000000000",
      "Code": "123456",
      "Name": "Foobar",
      "Type": "EXPENSE",
      "Description": "Hello World",
      "ValidationErrors": [
        { "Message": "Please enter a unique Name." }
      ]
    }
  ]
}
```

Xero's own documentation uses `Message` in one bulk example and `Description` in another for the same
feature, so the package reads both. It also walks the resource collection — the shape a
`summarizeErrors=false` bulk response uses, where each rejected item carries `StatusAttributeString: "ERROR"`
and its own `ValidationErrors`:

```json
{
  "Id": "bd364af7-08f0-432b-81db-c1e5ba05f3dd",
  "Status": "OK",
  "DateTimeUTC": "/Date(1552351488159)/",
  "Invoices": [
    {
      "InvoiceID": "0032a5d3-9c1a-4c0b-9c1e-6b0f6f3a1f21",
      "Type": "ACCREC",
      "Status": "DRAFT",
      "Date": "/Date(1551916800000+0000)/",
      "DueDate": "/Date(1552435200000+0000)/",
      "UpdatedDateUTC": "/Date(1551981568133+0000)/",
      "CurrencyCode": "MYR",
      "Total": 148062.76,
      "StatusAttributeString": "ERROR",
      "ValidationErrors": [
        { "Message": "Invoice # must be unique." }
      ]
    }
  ]
}
```

Note that response is an HTTP **200**. `invoices()->createMany()` sends `summarizeErrors=false` precisely so
one bad row does not reject the batch, and returns a `BatchResult` — so partial failures arrive through
`$result->failed()` and `$result->errorMessages()`, not as an exception. Call
`$result->throwIfAnyFailed()` if you would rather have one.

**b) HTTP 401/403 — the PascalCase problem envelope** → `XeroAuthenticationException`

```json
{
  "Type": null,
  "Title": "Unauthorized",
  "Status": 401,
  "Detail": "AuthenticationUnsuccessful",
  "Instance": "6a7f1b4e-0000-0000-0000-2d9c8e5a3f10"
}
```

**c) HTTP 503 — plain text, not JSON** → `XeroServiceUnavailableException`

```text
The Organisation is offline
```

**d) `identity.xero.com` — snake_case OAuth** → classified by `error`

```json
{
  "error": "invalid_grant",
  "error_description": "refresh token expired"
}
```

Building the message from these envelopes rather than relying on Laravel's `->throw()` also sidesteps its
120-character truncation of `RequestException` messages, which would otherwise hide the very validation
errors you need.

### A worked queued job

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Exceptions\XeroConnectionNotFoundException;
use Peoplelogy\XeroBridge\Exceptions\XeroIdentityUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Exceptions\XeroScopeException;
use Peoplelogy\XeroBridge\Exceptions\XeroServiceUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

class CreateXeroInvoice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        if ($order->xero_invoice_id !== null) {
            return;
        }

        try {
            $invoice = XeroBridge::invoices()->create([
                'Type' => 'ACCREC',
                'Contact' => ['ContactID' => $order->xero_contact_id],
                'Date' => $order->invoiced_on->toDateString(),
                'DueDate' => $order->due_on->toDateString(),
                'Reference' => $order->reference,
                'Status' => 'AUTHORISED',
                'LineItems' => [[
                    'Description' => $order->description,
                    'Quantity' => 1,
                    'UnitAmount' => $order->amount,
                ]],
            ], "order-{$order->id}");

            // Persist immediately. This, not the idempotency key, is the real
            // defence against a duplicate: Xero retains a key for six minutes
            // only, and any realistic backoff is longer than that.
            $order->update(['xero_invoice_id' => $invoice['InvoiceID']]);
        }

        // --- Wait and try again. Nothing is wrong with the payload. ---------

        catch (XeroRateLimitException $e) {
            // A daily-limit Retry-After can be hours, so never sleep inline.
            Log::warning('Xero rate limit hit.', $e->context());

            $this->release($e->retryAfter() ?? 300);
        } catch (XeroServiceUnavailableException $e) {
            // "The Organisation is offline" defaults to 300 seconds.
            $this->release($e->retryAfter() ?? 300);
        } catch (XeroIdentityUnavailableException $e) {
            // The stored tokens are untouched; this is always safe to retry.
            $this->release(120);
        }

        // --- Terminal. Retrying cannot help. --------------------------------
        // These two extend XeroAuthenticationException, so they must be caught
        // before any catch of the parent class.

        catch (XeroReauthorizationRequiredException | XeroScopeException $e) {
            Log::critical($e->getMessage(), $e->context());

            $this->fail($e);
        } catch (XeroConnectionNotFoundException | XeroConfigurationException $e) {
            Log::critical($e->getMessage(), $e->context());

            $this->fail($e);
        } catch (XeroValidationException $e) {
            // Identical input produces an identical rejection.
            $order->update(['xero_error' => implode('; ', $e->validationErrors())]);

            $this->fail($e);
        }
    }
}
```

The shape to copy: **release** for the three transient classes, **fail** for the terminal ones, and never
`release()` a `XeroValidationException` — the same payload will be rejected identically every time, and you
will simply burn quota until `$tries` runs out.

Name the write with `->for($order)` and switch the write ledger on (`XERO_WRITES_LEDGER=true`), and the
package keeps that record itself: a retry of a write that already happened is refused before anything is
sent. Two more exceptions can then arrive, and they need opposite handling: `XeroWriteAlreadyClaimedException`
— read `xeroId()`, or for a pending claim simply return, but never `release()` — and, with
`XERO_WRITES_STRICT=true`, `XeroWriteLedgerUnavailableException`, which is safe to `release()` because
nothing was sent. The catch recipe is in [Persistence](09-persistence.md#write-dedupe).

---

## Rate limits

Per organisation, Xero enforces:

| Limit | Value | `X-Rate-Limit-Problem` |
|---|---|---|
| Per minute | 60 calls | `minute` |
| Concurrent | 5 calls | `concurrent` |
| Per day | 1,000 on Starter, 5,000 above it | `day` |
| Per minute, whole app across all tenants | 10,000 calls | `appminute` |

Every response — not only a 429 — carries `X-MinLimit-Remaining`, `X-DayLimit-Remaining` and
`X-AppMinLimit-Remaining`. The package records them from each response and logs a `notice` when the minute
allowance drops below 5 or the daily allowance below 100. Read them yourself with:

```php
<?php

use Peoplelogy\XeroBridge\Facades\XeroBridge;

$status = XeroBridge::client()->lastRateLimit();

if ($status !== null && $status->isRunningLow()) {
    // ->minuteRemaining, ->dayRemaining, ->appMinuteRemaining, ->problem, ->retryAfter
    report_quota($status->toArray());
}
```

### What the package absorbs, and what it hands you

`http.retries` is **total attempts including the first**, defaulting to 3 (one try plus two retries).

| Situation | Absorbed? | Behaviour |
|---|---|---|
| Connection error or timeout | Yes | Always retried — the request may never have reached Xero. |
| 429 with `Retry-After` ≤ 30s (`http.retry_max_ms`) | Yes | Sleeps exactly that long. Laravel's HTTP client has no `Retry-After` support of its own; this is entirely the package's doing. |
| 429 with no `Retry-After` (the concurrency limit) | Yes | Short exponential backoff from 250ms, capped at 2 seconds. The concurrency limit frees in milliseconds, so backing off for a minute would be absurd. |
| 429 with `Retry-After` > 30s (typically the daily limit) | **No** | Thrown as `XeroRateLimitException` carrying the full `retryAfter()`, so a queued job can release itself rather than pin a worker for hours. |
| 500, 502, 504, and any 503 that is not an outage message | Yes | Exponential backoff from 1s with jitter, capped at 30s. If every attempt fails it is thrown as `XeroServiceUnavailableException`. |
| 503 "The Organisation is offline" / "offline for maintenance" | **No** | Thrown immediately with `retryAfter()` of 300. Xero suggests about five minutes, which is not an inline wait. |
| 401 or 403 | **No** | Never blind-retried. A 401 gets exactly one token refresh and one replay; an insufficient-scope 401 gets neither. |
| Any write (`POST`/`PUT`/`PATCH`/`DELETE`) when `http.idempotency` is `false` | **No** | Writes are not retried at all without an `Idempotency-Key`, because a retried POST that actually succeeded creates a duplicate invoice. |

When the package refuses to sleep, it guarantees `retryAfter()` is populated: if Xero sent no header, the
client fills it from `http.offline_retry_after` (default 300). So `$e->retryAfter() ?? 300` in the job above
is belt and braces rather than a real fallback.

Two things quota-related worth knowing: idempotency keys are checked **after** rate limiting, so duplicate
requests still consume quota; and a key is capped at 128 characters and retained by Xero for six minutes only.
