# Changelog

All notable changes to `peoplelogy/laravel-xero-bridge` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by hand, in the pull request that makes the change, under `## [Unreleased]`. Write
them for the *consumer*: "`Invoices::create()` now returns X instead of Y", not "refactor invoice DTO".

## [Unreleased]

## [1.6.0] - 2026-10-04

Applications that only call Xero — every one so far — need no webhook setting any more: with no
`XERO_WEBHOOK_KEY` there is no webhook route and no status warning. The whole upgrade is
`composer update peoplelogy/laravel-xero-bridge`.

### Fixed

- **`XERO_WEBHOOK_KEY=false` counted as a key.** `env()` reads the literal `false` as a boolean, which
  became an empty key that still counted as set. It now reads as no key, so writing it turns webhooks
  off like leaving the line blank.
- **The install instructions could leave a server asking for a GitHub token.** The `repositories` entry
  in the README and `docs/01-getting-started.md` now carries `"no-api": true`. Without it Composer looks
  versions up through GitHub's API, and when that lookup fails — 60 anonymous requests an hour, or a
  stale token saved on the machine — it asks for a token, or under `--no-interaction` falls back to
  cloning over SSH, which fails on a server with no key. Add the line to your own `composer.json`;
  nothing else changes. Both pages also say why the address must be `https://`, and that the package is
  then installed as a git checkout, so a deployment that runs `chmod` over `vendor/` wants
  `"discard-changes": true`.

### Changed

- **No `XERO_WEBHOOK_KEY`, no webhook route.** The webhook route is now registered only when a key is
  set and `XERO_WEBHOOKS_ENABLED` is on. Without a key the endpoint could only fail closed, answering
  401 to every delivery, so serving it added an open endpoint and a status warning and nothing else. An
  application that only calls Xero now needs no webhook setting at all: no key, no
  `XERO_WEBHOOKS_ENABLED=false`, and nothing set up in the Xero app.

  - `xero-bridge:status` no longer warns "No XERO_WEBHOOK_KEY is set, so every webhook will be rejected
    with a 401.", so `--strict` no longer fails for it: no key means webhooks are off, not that
    something is wrong. Nothing replaces the warning. Neither `--json` nor the test console's payload
    gains or loses a key; `webhooks_enabled` still reports the switch as set, so read it with
    `webhook_key_set` to know whether a route is served.
  - `xero-bridge:install` says webhooks are off when no key is set, and how to turn them on: register
    the URL it prints in the Xero app's Webhooks tab, put the key Xero shows into `XERO_WEBHOOK_KEY`,
    rebuild `config:cache` and `route:cache`, then press Send "Intent to receive". With a key it prints
    the webhook URL as before. With `XERO_WEBHOOKS_ENABLED=false` it names the setting:
    "Webhooks are disabled (XERO_WEBHOOKS_ENABLED=false), so there is no webhook URL to register."
  - The test console shows webhooks off in grey, not amber: the `XERO_WEBHOOK_KEY` row reads "not set
    — webhooks off — only needed if Xero calls this application", and the Webhook URL row reads "off".
  - `XERO_WEBHOOKS_ENABLED` is now only a kill switch: `false` removes the route even with a key set.
    Write `false` or `0` — `off` and `no` read as on.
  - Whether the route exists is decided when routes are registered, like any route flag: a
    `route:cache` built while a key was set keeps the route after the key is removed, and one built
    without a key lacks it once a key is set. A route kept that way still fails closed, answering every
    delivery with a 401. Rebuild `config:cache` and `route:cache` after setting or removing the key.

### Upgrade notes

Run `composer update peoplelogy/laravel-xero-bridge`. Nothing to publish, migrate or edit.

- **Projects that do not receive webhooks: nothing to do.** The webhook route and the status warning go
  by themselves. An `XERO_WEBHOOKS_ENABLED=false` line added for 1.5.0 can stay or go: either way there
  is no route.

- **Projects that do receive webhooks: nothing changes.** Their `XERO_WEBHOOK_KEY` is already set — it
  had to be, or every delivery was refused — so the route stays. A test that posts to it needs the key
  as the application boots, from `phpunit.xml` or the test environment: one set with `config()->set()`
  inside the test now comes too late, and the post gets a 404. See
  [Testing webhooks](docs/04-webhooks-and-events.md#testing-webhooks).

- **Guard any `route('xero-bridge.webhook')` of your own** — one that shows the URL on a settings page,
  say. Wherever no key is set the route is not registered, so `route()` throws. Check
  `Route::has('xero-bridge.webhook')` first, using your own `XERO_ROUTES_NAME_PREFIX` if you changed it.

- **Rebuild `route:cache` and `config:cache` after updating, if you cache them**, as for any deploy. A
  route cached before the update stays until you do; without a key it is still refused with a 401, as
  in 1.5.0.

## [1.5.0] - 2026-10-04

The whole upgrade is `composer update peoplelogy/laravel-xero-bridge`: nothing to publish, no
migration to run and no edit to a published config, because settings a release adds now reach a
published config by themselves. Two changes need a decision from you, and both fail closed if nobody
makes it -- the test console is off until you switch it on, and the invoice guard opt-ins work only
when chained. Read the upgrade notes at the end of this section before you deploy.

### Changed

- **The test console is off unless `XERO_CONSOLE_ENABLED=true`, in every environment.** Set it in each
  environment that should have the console, and narrow `XERO_CONSOLE_MIDDLEWARE` there.

  Until now an unset variable switched the console on unless `APP_ENV` was exactly `production`. A
  live host whose `APP_ENV` was `prod`, `live` or `Production` served it, and so did every staging and
  UAT box -- to anyone the default `web,auth` middleware admits, which is any signed-in user. What
  they got was the connected organisation's invoices and contacts, a forced token refresh, and a
  button that forgets the stored connection.

  Now only a value that reads as true -- `true`, `1`, `on`, `yes` -- switches it on. Unset, empty,
  `false`, `off`, `no` and anything unrecognised are off, and `APP_ENV` plays no part. A string
  `'false'` written into a published config, or set with `config()->set()`, used to switch the console
  *on* through a `(bool)` cast; it now means off. The gate is applied when the routes are registered
  and again on every request, so a route cache built while the console was on answers 404 wherever it
  is now off.

- **A published `config/xero-bridge.php` now receives new settings by itself.** Laravel merges a
  package's config one level deep, so a published file kept its own copy of every block and never saw
  a key a later release added inside one: the variable documented for that key silently did nothing.
  On every boot that does not run from a cached config, the package now adds each key your file lacks,
  at any depth, from its own config file. A published `config/myinvois.php` is filled the same way.

  A key you already have is never changed, whatever its value: `null`, `false`, `''` and `[]` are
  decisions. A list, such as a middleware stack, is one value and is never topped up, and the
  `connections` block, keyed by your own connection names, is left as you wrote it. Two consequences:

  - A config published before 1.4.0 now receives `webhooks.unique_for`, `webhooks.tries` and the
    `webhooks.dedupe` block, so `XERO_WEBHOOK_UNIQUE_FOR`, `XERO_WEBHOOK_TRIES`, `XERO_WEBHOOK_DEDUPE`
    and its `_TABLE` and `_RETAIN_DAYS` siblings take effect if your environment already sets them.
    Until now they did nothing there.
  - A line you deleted from a published config comes back with the shipped default. To switch
    something off, set it to `false`, `null`, `''` or `[]` instead of deleting the line.

  Under a cached config nothing is filled, exactly as Laravel skips its own merge, so a new variable
  takes effect after `php artisan config:clear` or a fresh `config:cache`. The comments in a published
  file stay as they were published, which is harmless: nothing needs republishing.

- **`xero-bridge:status` checks the package's tables, the write ledger and the lock store, and
  `--strict` counts what it finds.** It still makes no call to Xero.

  - **Tables.** A connections table that is missing, cannot be read, or is not the package's own is
    printed as an error in place of the connection list, and the command exits `1` -- until now it died
    on an uncaught `QueryException`. While the write ledger, webhook replay dedupe or API capture is
    switched on, a same-named table that is not the package's is a warning naming the setting to
    change. A missing replay or capture table stays silent, since a flag without its table is a
    documented no-op. When the migration that creates a table is already recorded as run, the advice
    says so rather than sending you round to `migrate`, and asks first whether the table's setting or
    `XERO_DB_CONNECTION` changed after migrating: then the real table is elsewhere, and creating a new
    one would strand it.
  - **Write ledger.** With `XERO_WRITES_LEDGER` on, a missing or unreadable ledger table is a warning,
    and so is any claim pending for over an hour, in the words `xero-bridge:prune` uses.
  - **Duplicate migrations.** One of the package's Xero tables recorded as created by more than one
    migration -- what the duplicate-migration failure fixed in 1.4.3 can leave behind -- is a warning,
    because rolling back the later batch would drop the live table.
  - **Locks.** The lock pre-flight the console has run since 1.2.0 now runs here too, and the lock
    store is judged by its driver, not its name. An `array` store warns whether `XERO_LOCK_STORE` names
    it or it is the default. A `file` store warns when it is only the default, and is a note that
    `--strict` ignores when `XERO_LOCK_STORE` names it: file locks do serialise processes, on one
    server. The null store and a store that cannot lock are pre-flight warnings; an undefined or
    unreachable store is a pre-flight failure.
  - Warnings, pre-flight results, notes and one line about the test console now print even when
    nothing is connected. Until now `--strict` could exit `1` with no reason on screen.
  - `--json` appends `warnings`, `notes`, `preflight`, `table_problems`, `write_ledger` and `console`
    after `missing_config` and `connections`, which keep their names and order; each connection row
    gains `tokens_readable`, appended last.
  - **Connections read through your repository.** The connections table is checked only while the
    package's own `EloquentConnectionRepository` stores the connections, and it is the configured
    model's own table -- `xero-bridge.model`'s `getTable()` and `getConnectionName()` -- not whatever
    `xero-bridge.database.*` says. A host that bound its own `ConnectionRepository` gets no package-table
    problem and exits `0` when its connections read, as in 1.4.3. Whatever repository is bound, a read
    that throws is reported -- "Could not read the stored connections: ..." -- and exits `1`, where it
    used to end the command with an uncaught exception. The test console renders it in its Pre-flight
    panel instead of failing.
  - **A migration of your own is never taken for the package's.** A recorded
    `*_create_xero_connections_table` (or the ledger, replay or capture equivalent) counts as the
    package's only when its file in `database/migrations` reads the stub's config key, as every
    published copy does. Status never names a same-named migration of your own, never advises deleting
    its row and never counts it as a duplicate: deleting it would make the next `migrate` re-run your
    `Schema::create` over your table and halt the deploy. When the recorded file cannot be found, the
    advice says that it cannot check, and that it applies only if the migration is the package's.

  Exit codes keep their values: `1` for incomplete configuration, an unusable connections table, or
  stored tokens the current `APP_KEY` cannot decrypt (see Fixed), `2` when a connection needs
  re-authorising, and under `--strict` `1` for any warning or a lock
  pre-flight that did not pass. The consent-flow pre-flight is printed but never counted: without
  `XERO_REDIRECT_URI` the command derives the callback URL from `APP_URL`, where a browser derives it
  from the host it asked. Each run now also reads the `migrations` table, and takes and releases one
  cache lock to prove the store works.

- **`xero-bridge:install` reports what will actually happen.**
  - The test console's state comes from the gate the page applies -- `ON` or `off`, and why -- never
    from whether a route happens to be registered. With it come the URL and the middleware in front of
    it and, when it is on, which organisations it may write into, plus a warning if that middleware is
    exactly `web,auth` (any signed-in user), `web` alone (anyone at all, signed in or not) or nothing at
    all.
  - A warning that behind the shipped `web,auth` any signed-in user can connect an organisation, or
    repoint an existing connection at one of their own, naming `XERO_ROUTES_MIDDLEWARE`. A default
    install prints it. A louder one says anyone, signed in or not, can do it when
    `XERO_ROUTES_MIDDLEWARE` is `web` alone.
  - A warning for each migration of your own that has the name of one the package publishes -- all
    five, MyInvois's included. `vendor:publish` maps a package migration onto the first file in
    `database/migrations` whose name ends with that migration's name, so it takes yours for the
    package's: the package's own is never published, and `--existing` or `--force` would overwrite your
    file. The warning names your file and says to rename it, and its row in `migrations` if it has run,
    then publish again. With `--force`, while such a file shadows one of the four Xero migrations,
    `--force` is not applied to the migrations publish -- none is overwritten, yours included -- and
    the warning says so; the config is still forced. Publishing itself is unchanged.
  - The webhook URL is the registered route's own, so it follows the new `XERO_WEBHOOK_PREFIX`; with
    webhooks switched off it says so instead of printing a URL nothing answers.
  - When no valid redirect URI can be worked out, step 3 prints the package's callback URL under your
    route prefix, where it printed a hard-coded `xero/callback`, and a warning says why, in the words
    the connect flow itself would use.
  - It reports "Published the migrations": the tag has published four since 1.4.0, not only the
    connections table's.

- **The webhook endpoint's 500 is logged under a new message.** A 500 means the job could not be
  queued -- or, on the sync driver, that a listener threw while the job ran inside the request -- and
  the old text, "xero-bridge: failed to queue a Xero webhook.", described only the first. It now reads
  "xero-bridge: could not queue a Xero webhook, or (on the sync driver) a listener failed while
  processing it.", with the exception's class in the context.

- **The null store counts as a store that cannot lock.** It grants every lock at once and so excludes
  nothing, and a token refresh now gives it the one-time warning a store without locks gets. That
  warning now reads "xero-bridge: the configured cache store cannot lock -- it supports no locks, or,
  like the null store, grants every one at once -- so Xero token refreshes are unsynchronised. ...";
  it said "does not support locking". Refreshes behave exactly as before.

- **Documentation corrected wherever it had drifted from the code.** Documentation only, nothing to
  do. The notable corrections:
  - **`withDefaults()`.** Three pages still said an override was ignored once a resource had been
    resolved, or leaked into later calls -- the bug fixed in 1.0.1. An override applies to that one
    expression, and the pages now say so.
  - **`redirectAfterConnectUsing()`.** Getting started said the OAuth callback never consults it. It
    has since 1.0.1.
  - **Sessions.** Connect and callback need a session -- the browser comes back from Xero with no
    bearer token -- so an API-only or SPA host needs a persistent session driver and
    `SESSION_SAME_SITE=lax`. A Sanctum cookie SPA works with `XERO_ROUTES_MIDDLEWARE=web,auth:sanctum`.
  - **Who can connect.** Under the default `web,auth`, any signed-in user can connect an organisation
    or repoint an existing connection at one of their own. The README now says so, as the install
    command does.
  - **Uninstalling.** The README rolled back one migration with `--step=1`, which since 1.4.0 rolls
    back the API capture table and leaves the token table behind. It now rolls each package migration
    back by its own file and batch.
  - **Scopes.** `openid profile email` are of no use to the package, which discards the id_token, and
    `accounting.attachments` has no wrapper. The default is unchanged; the docs now give a narrower
    set for invoicing alone, and say that narrowing an existing connection takes a revoke and a
    reconnect.
  - **Licence.** `LICENSE.md` and the README now say the source is publicly visible so the group's
    applications can install it without credentials, and that visibility grants no licence. The
    package is still proprietary.
  - **The circulated reference.** `docs/xero-bridge-usage` -- Markdown, HTML and PDF -- now describes
    1.5.0; it still said 1.3.0.
  - Smaller ones: seven events and four commands where the pages said six and three; both
    write-ledger exceptions in the exception hierarchy; no missing-`Contact` check among the invoice
    guards, which never made one; testing snippets that agree with the testing page; and no example
    that names a consuming application.

### Added

- **Strict mode for the write ledger: `XERO_WRITES_STRICT=true`.** With the ledger on, a write named
  with `for()` whose claim cannot be recorded -- the table is missing, the database is unreachable, or
  the insert fails for any reason other than a duplicate -- is refused before the request leaves, with
  the new `XeroWriteLedgerUnavailableException`. Nothing reached Xero, so retrying is safe. The
  default is off, which keeps the old behaviour: the failure is logged and the write goes out
  unprotected.

  ```php
  use Peoplelogy\XeroBridge\Exceptions\XeroWriteAlreadyClaimedException;
  use Peoplelogy\XeroBridge\Exceptions\XeroWriteLedgerUnavailableException;

  try {
      $invoice = XeroBridge::invoices()->for($order)->create($payload);
  } catch (XeroWriteLedgerUnavailableException $e) {
      report($e);
      $this->release(60);   // nothing was sent: retry once the ledger is back

      return;
  } catch (XeroWriteAlreadyClaimedException $e) {
      if ($e->isPending()) {
          return;           // never re-send; XeroWriteBlocked tells monitoring
      }

      $invoiceId = $e->xeroId();
  }
  ```

  Both extend `XeroBridgeException` and mean opposite things -- stop on the one, retry on the other --
  so a catch-all that marks a record failed has to single the new one out. Only owned writes are ever
  refused: an unowned write is never deduplicated, so there is nothing to protect. Strict mode does not
  consult the per-process table check either; the insert decides, so a table migrated under a running
  worker is used at once. It does not cover a write inside a database transaction (still a warning),
  `createMany()`, `XeroBridge::request()` and `raw()`, or recording Xero's answer once a write has
  succeeded, which never throws. The package does not log the refusal itself, so a job that catches it
  to retry should, as above.

- **`XeroWriteBlocked`, an event for every write the ledger refuses as a duplicate.** It is dispatched
  just before each `XeroWriteAlreadyClaimedException`, for invoices, contacts and payments alike, with
  `connectionKey`, `operation`, `ownerType`, `ownerId`, `reference`, `pending`, `xeroId` and
  `claimedAt`. The documented catch recipe returns quietly on a pending block, so until now a stuck
  claim went unheard unless somebody ran `xero-bridge:prune`. Read it by its shape: `pending` false is
  the ledger working; `pending` with a `claimedAt` more than a few minutes old is a stuck claim worth
  paging on; seconds old, two workers raced and the loser was stopped.

  ```php
  use Peoplelogy\XeroBridge\Events\XeroWriteBlocked;

  Event::listen(function (XeroWriteBlocked $blocked) {
      $stuck = $blocked->pending
          && ($blocked->claimedAt === null || $blocked->claimedAt->lt(now()->subMinutes(10)));

      if ($stuck) {
          report(new RuntimeException(
              "Stuck Xero claim: {$blocked->operation} for {$blocked->ownerType}#{$blocked->ownerId}"
          ));
      }
  });
  ```

  A listener that throws is logged, and the caller still gets its exception. That exception now names
  its connection too, through `connectionKey()` and `context()['connection']`, where it had none.

- **Webhooks for organisations you have not connected can be dropped, with
  `XERO_WEBHOOK_UNKNOWN_TENANTS=ignore`.** Xero delivers events for every organisation connected to the
  Xero app -- one connected from another environment, or one forgotten here and never disconnected at
  Xero. With `ignore`, an `ORGANISATION` event whose tenant has no stored connection is skipped before
  any listener runs: never dispatched, never recorded, with one info line per delivery. App Store
  subscription events (`APPLICATION`) are never skipped, an invalidated connection still counts as
  connected, and an event whose lookup fails is dispatched rather than lost. The default, `dispatch`,
  is the old behaviour and costs no query; any other value also means `dispatch`. The queue worker
  reads the setting, so after changing it clear or rebuild a cached config and restart the workers.

- **`XeroWebhookReceived::connection()`**, the stored connection for the event's organisation, or
  `null` -- the check a listener needs while unknown organisations are still dispatched:

  ```php
  use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;

  Event::listen(function (XeroWebhookReceived $event) {
      $connection = $event->connection();

      if ($connection === null || ! $connection->isUsable()) {
          return;   // not an organisation this app connected, or one that must reconnect first
      }

      // ...
  });
  ```

  It is looked up when called, with one query per call, and never carried on the event: a queued
  listener can run after a key was repointed or an organisation re-keyed, and the tenant id is what
  holds. Invalidated connections are returned too, and `APPLICATION` events always get `null`. It adds
  no property, so events queued before you deploy still unserialise.

- **A prefix of the webhook's own: `XERO_WEBHOOK_PREFIX`.** `XERO_WEBHOOK_PREFIX=api/v1/xero` serves the
  webhook at `/api/v1/xero/webhook` while connect, callback and the console stay under
  `XERO_ROUTES_PREFIX`. Unset or empty follows `XERO_ROUTES_PREFIX`, so no URL moves until you set it,
  and `/` means the site root. The route keeps its cookie-free middleware under any prefix and gains
  no group, not even under an `api/` prefix. Moving the webhook means re-entering the URL in the Xero
  app's Webhooks tab, where Xero re-runs its intent-to-receive check, and rebuilding `route:cache`.

- **`XeroConnected` says who connected: `$event->actor`.** It is a `Peoplelogy\XeroBridge\OAuth\Actor`
  holding the signed-in user's `id` (an int or a string, as the guard returned it), `type` (the morph
  alias when one is mapped, otherwise the class name) and `guard` -- or `null` when nobody was signed in
  on the callback, or the user could not be resolved. Plain scalars and never the user model: the
  package's events do not use `SerializesModels`, so a model would carry the password hash into every
  queued listener's payload and into `failed_jobs`. Read it as `$event->actor?->id`.

  Existing listeners, `new XeroConnected($connection, $wasRepointed)` and `Event::fake()` assertions
  are unaffected; the actor is an optional third argument. `$actor` is the one property on the event
  that is not readonly, so that an event queued by 1.4 still unserialises -- with `null`. `Actor` is
  public API, like the event that carries it, and its constructor is public so a test can build one.

- **The test console logs who forgot a connection.** "Forget" now writes a warning naming the key, the
  tenant id, Xero's connection id -- which `DELETE /connections/{id}` needs to finish the disconnect at
  Xero -- and the actor's id, type and guard, as scalars. It is the only connection deletion a person
  triggers by hand, and nothing else recorded it. Nothing is logged when no row was deleted. The
  model's `deleting` and `deleted` events still fire, so your own observers see it too.

### Fixed

- **After an `APP_KEY` rotation, `XeroConnection::isUsable()` threw instead of answering, and
  `xero-bridge:status` died with a stack trace.** Without `APP_PREVIOUS_KEYS`, the old key's tokens can
  no longer be decrypted, and `isUsable()` -- the guard these docs tell every webhook listener to use --
  read the encrypted refresh token and let the `DecryptException` escape: the listener failed, the
  webhook job retried, and status reported nothing. `isUsable()` now returns false for a connection
  whose tokens cannot be read. Status keeps reporting: each connection row gains `tokens_readable`
  (appended last in `--json`), the Token column reads `unreadable` in red, and a warning names the
  connection and says to put the old key in `APP_PREVIOUS_KEYS` or re-authorise. Plain status and
  `--json` exit `1` for such a connection -- the code 1.4.3 exited with, by crashing -- unless it is
  already invalidated, which still exits `2` because re-authorising replaces the tokens. The test
  console shows a red "tokens unreadable" pill where it showed "healthy". Using the token for real
  still fails with the configuration error, which now names the cheaper way out too: "The stored Xero
  tokens for connection [<key>] cannot be decrypted. This normally means APP_KEY changed since they
  were saved. Put the old key in APP_PREVIOUS_KEYS, or re-authorise at <url>." It used to end
  "Re-authorise at <url>.".
- **The test console page no longer fails with a 500 when the connections table is missing or not the
  package's.** It used to read the connections first and die on the query; it now renders, with the
  problem in its Pre-flight panel.
- **Webhook listeners could still run twice: the uniqueness lock announced in 1.4.0 was never taken.**
  `ProcessXeroWebhook` declares `ShouldBeUnique`, but Laravel takes that lock only when a job goes out
  through `dispatch()`, and the controller queues through the bus dispatcher. So a 200 that missed
  Xero's five seconds still let Xero's retry queue a second job. The controller now takes the lock
  itself, keyed on the events rather than on `entropy`, which Xero varies per delivery: a retry of the
  same events while the first job is queued, waiting between attempts or running is answered 200 and
  not queued again. Laravel releases the lock when the job finishes or its last attempt fails;
  `XERO_WEBHOOK_UNIQUE_FOR` (default 900 seconds) is only the ceiling for a worker that died. A failed
  push gives the lock back before the 500, so Xero's retry is queued normally, and a lock store that
  is down lets the delivery through without a lock -- a possible duplicate, never a loss.

  A 200 for a retry is only given once the first delivery is confirmed queued. Xero gives up on a
  request after five seconds and retries at once, so a retry can arrive while the first push is still
  in flight -- on the sync driver, while its listeners still run -- and a 200 then would lose the
  events if that push went on to fail. So after a successful push the controller stores a marker,
  `xero-bridge:webhook-queued:<uniqueId>`, beside the lock for `webhooks.unique_for` seconds. A retry
  that finds the lock held gets 200 when the marker is there, and otherwise a 503, logged at info
  ("xero-bridge: the first delivery of these Xero webhook events is not confirmed queued yet; asking
  Xero to retry later."), and Xero retries later: by then the first delivery is queued, and the retry
  gets 200 or is queued again as a duplicate the replay table absorbs, or it failed, and the retry
  queues the events. On the sync driver that means a retry arriving while the first request still
  runs costs a later duplicate rather than being dropped. A failed push forgets the marker before it
  gives the lock back. A marker that cannot be written is logged at warning ("xero-bridge: could not
  record that a Xero webhook was queued, ...") and the delivery still gets its 200; one that cannot be
  read counts as absent. If the lock cannot be given back after a failed push, that is logged at
  critical, with `exception`, `exception_class` and `unique_for`: "xero-bridge: could not release the
  uniqueness lock of a Xero webhook that was not queued; until it expires, Xero's retries of the same
  events are answered 503 without being queued."

  The lock lives in the store `XERO_LOCK_STORE` names, beside the token-refresh locks, or in the
  default store while that is unset. A store that cannot be resolved, cannot lock or uses the null
  driver falls back to the default store. With more than one web server, point `XERO_LOCK_STORE` at
  redis, memcached or database. The store must hold plain cache entries too, for the marker: a
  `database` store needs the `cache` table as well as `cache_locks`, or every delivery logs that
  warning. In your own tests, posting the same events twice under `Queue::fake()` now pushes one job.

- **A throwing `XeroWebhookSignatureFailed` listener turned the 401 Xero requires into a 500**, which
  fails Xero's intent-to-receive check as surely as accepting a bad signature would. The exception is
  now logged ("xero-bridge: a XeroWebhookSignatureFailed listener failed; answered 401 regardless.")
  and the empty 401 always goes out; it no longer reaches your exception handler. The listener still
  runs inside the request, so keep it fast or queue it.

- **With `XERO_WEBHOOK_DEDUPE=true`, a database error while recording an event dispatched it again, up
  to five times.** Any error other than a duplicate key -- an altered table, a column added without a
  default, a lost connection -- was rethrown after the listeners had run: the job failed, every retry
  dispatched that event again, and the events behind it waited and were lost with the job. Recording
  now logs the failure and carries on. The worst case is a later delivery of that event being
  dispatched again, which listeners must tolerate anyway.

- **A duplicate was recognised by SQLSTATE `23000`, which on MySQL and SQLite also means a NOT NULL,
  foreign-key or CHECK failure.** The write ledger, the webhook replay table and the MyInvois verdict
  record now take only Laravel's `UniqueConstraintViolationException` as a duplicate. Before, a column
  added to one of their tables without a default made the ledger report a pending
  `XeroWriteAlreadyClaimedException` for a write that never happened -- which the documented recipe
  drops without a word, on every retry -- made the replay table count a redelivery of an event it never
  recorded, and lost MyInvois verdicts in silence. Each is now a storage failure, logged as one; the
  ledger then sends the write unprotected, or refuses it under `XERO_WRITES_STRICT`. One safeguard
  keeps the old right answer where it was right: when an owned write's insert fails on any integrity
  constraint, the ledger checks whether the claim is already held and, if it is, refuses the write as
  the duplicate it is, because some engines check NOT NULL before the unique index.

- **The write ledger's transaction warning read the default database connection.** It now reads the
  ledger's own, `XERO_DB_CONNECTION`: a transaction there warns, and one open only on another
  connection no longer raises a false warning.

- **The invoice guard opt-ins leaked into later writes.** `withContactMutation()` and
  `replacingLineItems()` set a flag on the instance `XeroBridge::invoices()` shares for the life of the
  process -- a whole queue worker, or every request under Octane. `update()` never cleared it, whether
  Xero accepted the update or not, and `->withContactMutation()->for($order)->create()` cleared only
  the copy `for()` had made. So the next plain update carrying lines without a `LineItemID`, or the
  next create or update carrying extra `Contact` fields, on that connection went out unguarded, and
  Xero replaced the lines or rewrote the contact. `createMany()` had the opposite fault: it cleared the
  opt-in after the first invoice and refused the rest of the batch.

  Both now return a copy with the opt-in set, as `for()` and `reference()` do. The opt-in covers every
  call made through that copy, each invoice of a `createMany()` included, works on either side of
  `for()`, and reaches nothing else. On a line of its own it does nothing: the guard refuses the
  payload with nothing sent, and its message now says to chain the opt-in. A copy kept in a variable
  stays opted in for every call made through it.

- **`for()->createMany()` looked protected and was not.** No claim is taken for a batch, so naming an
  owner protected nothing. The batch is still sent, since refusing would break every caller doing this
  today, but a warning now says so ("xero-bridge: createMany() was called on a resource named with
  for(), but only create() is protected by the write ledger. ..."). Call `create()` once per invoice to
  protect each one.

- **With `XERO_ON_TENANT_CONFLICT=rekey`, `XERO_ON_KEY_CONFLICT=error` was bypassed.** Connecting key
  `acme` to an organisation already stored under `beta`, while `acme` held a different organisation,
  deleted `acme`'s row before the key conflict was checked: its tokens were gone and `beta` moved in.
  The key conflict is now checked first. Under `replace`, the default, nothing changes; anything else
  refuses with `ConnectionKeyConflictException`, both rows untouched, and the administrator sees why.

- **Your own code could turn the OAuth callback into a 500.** A `XeroConnected` listener that threw (or
  a queued one whose push failed), a `redirectAfterConnectUsing()` closure that threw, and an
  `XERO_AFTER_CONNECT_ROUTE` whose URL could not be built -- a route with required parameters -- each
  did it, even after the connection had been stored. Each is now reported to your exception handler and
  logged, and the administrator lands with the message the outcome earned. A closure that throws counts
  as one that returned nothing, so the configured destination decides, and an unbuildable named route
  falls through to `XERO_AFTER_CONNECT_REDIRECT`. A listener can no longer replace the callback's
  response by throwing; `redirectAfterConnectUsing()` is the way to choose where the administrator
  lands.

- **Migrations no longer take over a same-named table they did not create, and a rollback never drops
  one.** In 1.4.3 a package migration whose table already existed returned early, whoever's table it
  was. Over a table of the host's own -- an earlier integration's `xero_connections`, say -- `migrate`
  recorded the package migration as run, and the clash surfaced later, against a table the package
  cannot use. Worse, every `down()` dropped its table unconditionally, so a rollback, the documented
  uninstall included, dropped the host's table and its data.

  `up()` now compares an existing table with the columns the migration originally created. Its own
  table is still a no-op -- the republished-duplicate case still just works, and columns you added are
  fine -- and anyone else's is refused before any DDL, with a message naming the physical table, the
  missing columns and the setting to change: `XERO_DB_TABLE`, `XERO_WRITES_TABLE`,
  `XERO_WEBHOOK_DEDUPE_TABLE`, `XERO_CAPTURE_TABLE` or `MYINVOIS_AUDIT_TABLE`. A refused migration is
  not recorded, so the next `migrate` resumes at it. `down()` drops only a table that is the
  migration's own.

  This reaches migrations published from 1.5.0 on. Files you already published are yours and do not
  change on `composer update`; `xero-bridge:status` now reports a foreign connections table one of them
  adopted, and a foreign write-ledger, replay or capture table while that feature is on (the MyInvois
  table is not checked).
  Republishing them is optional -- see the upgrade notes. What `down()` still cannot do is tell two
  copies of the same migration apart; see the duplicate-migration note below.

- **The test console showed credentials and bank details.** The JSON every console action returns now
  has each value whose key is a credential or a bank field replaced with the capture placeholder
  (`XERO_CAPTURE_PLACEHOLDER`, default `[redacted]`), at any depth and subtree included:
  `Organisation.APIKey`, a live Xero-to-Xero credential; the organisation's own `BankAccountNumber`
  from `GET /Accounts`; a contact's `BankAccountDetails` and `BatchPayments`. It is the list the capture
  table can never store, matched on the whole key and case-insensitively, so `BankAccountType`,
  `AccountNumber` (your own customer code), `TaxNumber` and everything else stay visible, and a large
  page is never truncated.

- **The console reported a forget that a model listener had cancelled.** When a `deleting` listener on
  the connection model vetoed the delete, "forget" still answered `deleted: true`. It now answers
  `deleted: false`, says why, and logs nothing. Its notes also say plainly what forgetting does: every
  call through that key fails until someone reconnects.

- **The console's "Lock store" pill judged the store by its name.** It flagged a `file` store chosen on
  purpose and missed a file store under another name, the null store and stores that cannot lock. It
  now shows what `xero-bridge:status` decides, from the store's driver: amber "one process only", "one
  server only" or "no locking" where status warns, and a grey "one server only" for a file store that
  `XERO_LOCK_STORE` chose.

- **A blank `XERO_LOCK_STORE=` failed every token refresh on recent Laravel 12 releases and on 13.**
  Those releases look an empty store name up as a name and throw "Cache store [] is not defined", where
  Laravel 11 and earlier 12 releases used the default store -- and `xero-bridge:install` prints the key
  blank, ready to copy. An empty value, or `false`, now means the default store on every release, for
  token refreshes, the webhook lock and the status checks alike.

- **One failed table check switched a recorder off until the worker restarted.** When the database
  could not be asked whether a package table existed -- a blip at a queue worker's first write -- the
  answer "no" was kept for the life of the process: the ledger protected nothing, and replay dedupe,
  capture and the MyInvois record recorded nothing, until a restart that under `queue:work` can be days
  away. A failed check now stands for 60 seconds and is then asked again; a definite yes or no is still
  kept for the process. Its log line now ends "...so recording into it is off for now; the check is
  tried again a minute later."

- **`xero-bridge:prune` logged a warning for every absent table on every run, naming the wrong publish
  tag for MyInvois.** It now checks quietly and prints nothing for a table you never published; `-v`
  lists each one with the tag that creates it (`myinvois-migrations` for the verdict table). A table
  check that fails prints an error line, where it was silent on screen. Exit codes are unchanged.

- **`xero-bridge:prune` deleted rows from tables that were not the package's.** Since 1.4.0 it pruned
  any table under a package table name -- a host's own `xero_api_calls`, say -- deleting its rows past
  the retention window every night, even with that feature off, because the retention query filters on
  age alone. Prune now first checks that the table has the package's own columns (`dedupe_key`,
  `delivery_count`, `first_seen_at` for webhook replay records; `claim_key`, `connection_key`,
  `claimed_at` for the write ledger; `logical_call_id`, `channel`, `created_at` for captured calls;
  `subject_hash`, `tin_last4`, `last_checked_at` for MyInvois verdicts) and skips any other with an
  error: "The table [<t>] is not the package's <label> table (it has no <columns> columns), so nothing
  in it was pruned. If it is yours, set <ENV KEY> to an unused name." Exit codes are unchanged.

- **The test console's error envelope carried a database error's SQL.** Laravel writes the SQL, with
  every binding filled in, into a `QueryException`'s message -- for a token save that failed, the new
  tokens' ciphertext. `error.message` is now the driver's message beneath it (for example
  "SQLSTATE[23000]: Integrity constraint violation: ..."), or "A database query failed." when there is
  none. `type` and `class` are unchanged.

### Upgrade notes

Run `composer update peoplelogy/laravel-xero-bridge`. Nothing to publish, migrate or edit.

Then, where each applies to you:

- **Keep the test console where you want it.** Set `XERO_CONSOLE_ENABLED=true` in each environment
  that should keep it -- with `XERO_CONSOLE_MIDDLEWARE` narrowed to your own admin gate -- then run
  `php artisan config:clear` and `php artisan route:clear`, or rebuild both caches. Without it the
  console is gone everywhere, local and staging included, which is the safe direction. A published
  copy of the console view keeps its old ribbon text and lock pill until you republish it with
  `php artisan vendor:publish --tag=xero-bridge-views --force`.

- **Guard links to the console.** Wherever the console is off its route is not registered, so
  `route('xero-bridge.console')` throws. Check `Route::has('xero-bridge.console')` first, using your
  own `XERO_ROUTES_NAME_PREFIX` if you changed it.

- **Chain the invoice opt-ins.** `withContactMutation()` and `replacingLineItems()` return a copy now,
  so the statement form no longer relaxes the guard. It fails closed -- the guard throws and nothing is
  sent -- so search for `->replacingLineItems();` and `->withContactMutation();`:

  ```php
  $invoices = XeroBridge::invoices();

  // No longer relaxes the guard: the opt-in lands on a copy nobody uses.
  $invoices->replacingLineItems();
  $invoices->update($invoiceId, $invoice);

  // Chained, as it has to be now.
  XeroBridge::invoices()->replacingLineItems()->update($invoiceId, $invoice);
  ```

- **Expect `xero-bridge:status --strict` to fail where it passed.** Monitoring may newly see exit `1`
  for: a table that is not the package's own under an enabled ledger, replay dedupe or capture; the
  write ledger on with its table missing or unreadable; a write claim pending for over an hour; a
  package migration recorded twice; and the lock store, now judged by its driver -- an `array` store
  even when `XERO_LOCK_STORE` names it, a `file` store that is only the default whatever it is called,
  the null store, a store that cannot lock, and an undefined or unreachable one. Plain `status`, without
  `--strict`, now exits `1` with a message, where it used to throw, when the connections table is
  missing, unreadable or not the package's own, when the stored connections cannot be read, and when a
  connection that is not invalidated holds tokens the current `APP_KEY` cannot decrypt -- each of which
  1.4.3 also exited `1` for, by crashing. On a single server, `XERO_LOCK_STORE=file` turns the
  default-file warning into a note that `--strict` ignores.

- **Update alerts keyed on log text.** The webhook endpoint's 500 now logs "xero-bridge: could not queue
  a Xero webhook, or (on the sync driver) a listener failed while processing it." in place of
  "xero-bridge: failed to queue a Xero webhook.". The one-time lock warning ("does not support
  locking") and the failed-table-check line ("so recording is off for this process") are reworded too,
  as described above. Two webhook lines are new and worth an alert: the critical "xero-bridge: could
  not release the uniqueness lock of a Xero webhook that was not queued; ..." and the warning
  "xero-bridge: could not record that a Xero webhook was queued, ...". If you built a rule on a
  pre-release build of 1.5.0, match the critical line's prefix: its ending now reads "answered 503
  without being queued.", where it read "answered 200 without being queued.".

- **Give a `database` lock store its `cache` table.** The webhook lock now keeps a plain cache entry
  beside each lock, so a store with only `cache_locks` logs the warning above on every delivery, and
  retries during a held lock are answered 503 and queued again later.

- **If a deployment ever stopped on `1050 Table ... already exists`**, the duplicate-migration failure
  fixed in 1.4.3, run `php artisan xero-bridge:status`. If it reports a package table "created more
  than once", delete the LATER migration file and its row in the `migrations` table -- keep the first --
  and never roll back a batch that still contains it: its `down()` cannot tell the two copies apart and
  would drop the live table.

- **Optionally, republish the migrations** to give the files you already published the new `up()` and
  `down()`, as [Migrations you have already published](README.md#migrations-you-have-already-published)
  explains:

  ```bash
  php artisan vendor:publish --tag=xero-bridge-migrations --existing
  php artisan vendor:publish --tag=myinvois-migrations --existing
  ```

  Each file is rewritten in place under its own name, so its row in `migrations` still matches and
  `migrate` has nothing to run afterwards. Any edit you made to those files is overwritten. `--force`
  rewrites them too, with a caveat: it also publishes every migration of the tag you never published,
  each one a new migration that creates its table at your next `migrate` -- which `--existing` leaves
  alone. Where one migration was published twice, only the earlier copy is rewritten; delete the later
  one as in the note above.

  Check `git status` afterwards. `vendor:publish` maps each package migration onto any existing file in
  `database/migrations` whose name ends with that migration's name, so `--existing` and `--force` also
  rewrite a migration of your own with such a name -- `2023_05_01_000000_create_xero_connections_table.php`,
  say -- and the package's migration is never published beside it. Restore such a file from version
  control. `xero-bridge:install` now detects this, warns, and refuses to force over it.

- **Restart your queue workers, as for any deploy** (`php artisan queue:restart`). Work queued before
  the deploy runs unchanged: webhook jobs keep their payload, and a queued `XeroConnected` listener
  sees `$event->actor` as `null`.

## [1.4.3] - 2026-09-29

### Fixed

- **`php artisan migrate` could stop a deployment with `1050 Table ... already exists`.** Run
  `php artisan migrate` again and it now completes; nothing else is needed.

  A package migration is *published*, and its filename carries the timestamp of the moment somebody
  published it — not a fixed one the way an application's own migration does. Two environments that
  publish at different moments therefore end up with two different filenames for the same table.
  Commit one of them, deploy to a server that had already published its own, and Laravel sees a
  migration it has never run, tries to create a table that is already there, and stops — halfway
  through, with some tables created and some not.

  Every migration this package ships now returns early when its table exists. These tables are
  created once and never altered by these files, so there is nothing a second run needs to catch up
  on, and an existing table is treated as work already done rather than as a conflict.

  `tests/Unit/MigrationRerunTest.php` runs every migration a second time and fails without the guard,
  reproducing the exact error.

### Changed


- **The Laravel 11 end-of-life notice in the README is now four sentences and a link**, rather than a
  page restating Laravel's release policy.

  What it said was accurate when written and would not have stayed that way: it named a specific
  date, a specific advisory, and four specific patch versions, none of which this package is in a
  position to keep current. A security statement that quietly goes stale is worse than none, because
  a reader has no way to tell. It now links to Laravel's own support policy, which does stay current,
  and keeps only the part that is genuinely about this package: Laravel 11 is best-effort, its CI legs
  are non-blocking, and a `composer update` refused over an advisory is a fact about the consuming
  application rather than about the bridge.

  For the record, since the detail is now out of the README: Laravel 11 stopped receiving security
  fixes on 12 March 2026. Three advisories affect the whole 11.x line, including CVE-2026-48019, a
  high-severity CRLF injection in the default email validation rule, fixed in 12.60.0 / 12.61.1 /
  13.10.0 / 13.12.0 and not backported.


## [1.4.2] - 2026-09-29

### Fixed

- **`php artisan migrate` failed on MySQL** with `SQLSTATE[42000] ... 1067 Invalid default value for
  'last_checked_at'`. The four new tables could not be created at all. Republish the migrations and
  run `migrate` again:

  ```bash
  php artisan vendor:publish --tag=xero-bridge-migrations --force
  php artisan vendor:publish --tag=myinvois-migrations --force
  php artisan migrate
  ```

  MySQL treats a `TIMESTAMP NOT NULL` column with no explicit default in two different ways, and both
  were biting. Every column after the first gets an implicit zero-date default, which strict mode
  rejects outright -- that is the error above, and it is the loud half.

  The quiet half was worse and had not been noticed yet: the **first** such column in a table gets
  `DEFAULT CURRENT_TIMESTAMP` **`ON UPDATE CURRENT_TIMESTAMP`**. Every one of these columns is written
  once and then read as evidence, so MySQL would have silently rewritten `claimed_at` each time a
  write claim was confirmed, `first_seen_at` on every webhook replay, and `first_checked_at` on every
  re-check. That would have turned "first" into "last" with no error anywhere -- and it would have
  disabled stuck-claim detection entirely, because `scopeStuck()` compares `claimed_at` against an
  hour ago and `claimed_at` would never have been more than a moment old.

  Naming the default explicitly fixes both, and the columns keep `NOT NULL`.

  The test suite could not have caught this: it runs against in-memory sqlite, which has none of this
  behaviour. `tests/Unit/MigrationTimestampTest.php` now reads the migration stubs as text and fails
  if a non-nullable timestamp column is missing an explicit default, which is the only way to assert
  it without a MySQL connection in CI.

## [1.4.1] - 2026-09-29

### Fixed

- **The sample config block in the README no longer switches the new features off.**
  It listed the shipped defaults, so copying it verbatim left MyInvois TIN validation and API call
  capture disabled -- which reads identically to "I configured this" from the outside, and is exactly
  how a MyInvois panel went missing after the block was pasted. `MYINVOIS_ENABLED` and
  `XERO_CAPTURE` now both read `true`, with the package default stated in the comment above each, and
  each names the step people forget: `myinvois-config` is its own publish tag, and capture records
  nothing until its migrations are published and run.

  Documentation only. No code changed between 1.4.0 and 1.4.1.

## [1.4.0] - 2026-09-29

### Added

- **Every Xero and LHDN call, recorded for your own dashboard.** Set `XERO_CAPTURE=true`, publish
  and run the migrations, and the request and response of every call lands in `xero_api_calls` for
  your application to render however it likes. The package ships no viewer on purpose -- your roles
  decide who may see customer data, not ours -- and `/xero/console` is unchanged.

  ```php
  use Peoplelogy\XeroBridge\Models\XeroApiCall;

  XeroApiCall::forOwner($order)->latest()->get();
  XeroApiCall::failed()->whereDate('created_at', today())->get();
  ```

  Name the record a call belongs to, and its rows can be joined back to it:

  ```php
  app(ApiCallRecorder::class)->forOwner($order, fn () => XeroBridge::invoices()->create([...]));
  ```

  **Bank details and credentials never reach the table.** Redaction happens before the insert, not
  on the way out, and neither group can be switched back on: the bearer token, the access, refresh
  and id tokens, the client secret, `Organisation.APIKey`, your own and your customers' bank account
  numbers, and the whole `BatchPayments` block. The Malaysian TIN is masked to its last four, so a
  row still joins to its `myinvois_validations` verdict; the TIN in the MyInvois URL path and the
  NRIC or BRN in its query string are removed, neither being reachable by any rule that walks a
  body. What is deliberately KEPT is the part worth reading: `Contact.AccountNumber` (which is not a
  bank account -- it is normally your own customer code), `BankAccountType`, `Account.Code`,
  `CompanyNumber`, `correlationId`, `Idempotency-Key` and `Xero-tenant-id`.

  Defaults: off; `writes` mode, which skips successful reads but never skips a failure; 90-day
  retention through `xero-bridge:prune`, which now prunes this table too. See
  [docs/10-api-capture.md](docs/10-api-capture.md).

  A project that upgrades and does nothing is unaffected: no table, no rows, no behaviour change.

- **Duplicate protection for writes into Xero.** Xero honours an idempotency key for only six
  minutes, which no realistic queue backoff stays inside -- so a job retried an hour later has
  always been able to create a second invoice. Name the record a write belongs to and that cannot
  happen:

  ```php
  XeroBridge::invoices()->for($order)->create([...]);
  ```

  A second attempt throws `XeroWriteAlreadyClaimedException` carrying the id already created, and
  nothing reaches Xero. Works for invoices, contacts and payments. Use `->for($order, 'deposit')`
  when one record legitimately needs two writes.

  The claim is recorded BEFORE the request leaves, which decides what happens when things go wrong.
  A 400 proves nothing was created, so the claim is released for a corrected retry. A timeout, a
  5xx or a killed worker proves nothing at all, so the claim stays pending and BLOCKS further
  writes for that record at any age -- nothing re-sends on its own. That swaps a duplicate invoice
  in a customer's ledger for one stuck row a human clears, which `xero-bridge:prune` reports.

  Do not wrap a Xero write in a database transaction: the claim must be committed before the HTTP
  call, and a rollback after Xero accepted the invoice would erase the only record that it exists.

- **Durable webhook replay dedupe.** Xero stores undelivered events for up to 31 days and replays
  them. With `XERO_WEBHOOK_DEDUPE=true` an event already dispatched is skipped and its
  `delivery_count` incremented -- the only direct evidence that the window is being exercised. This
  does NOT make your listeners idempotent and is not a substitute for writing them that way.

- **A MyInvois verdict record.** With `MYINVOIS_AUDIT=true`, every TIN validation is recorded, both
  answers, with LHDN's correlation id -- which until now was read only when building an exception,
  so every actual verdict discarded it.

  The TIN and the identifier are NOT stored. They appear only as a keyed HMAC, because a Malaysian
  registration number is twelve digits and a plain hash of one is exhausted on a laptop in under a
  second. Readable columns are the id type, the last four characters of the TIN, the verdict, the
  status, the correlation id, the environment and a rules version. `forgetSubject()` and
  `forgetOwner()` handle erasure.

  Note the cost: the HMAC key derives from your application key, so rotating `APP_KEY` orphans
  every stored hash.

- **`xero-bridge:prune`**, one scheduled command for all three tables, with `--dry-run`. It exits
  non-zero when a write claim has been pending over an hour, because each one blocks writes for its
  record.

- **`WebhookEvent::dedupeKey()`**, so a consumer building their own dedupe cannot get the key wrong.
  It is `tenantId|resourceId|eventType|eventDateUtc` -- deliberately NOT `entropy`, which Xero
  varies between deliveries of the same logical event.

- **`MyInvoisClient::lastCorrelationId()`**, readable without switching the verdict table on.

### Changed

- **`ProcessXeroWebhook` is now a unique job, and retries.** This is the one behaviour change that
  arrives on `composer update` with no migration and no configuration, and it fixes a duplicate
  path that existed for every webhook consumer: the controller dispatches the job BEFORE returning
  its 200, so a response missing Xero's five-second budget meant the retry queued a second
  identical job and every listener fired twice.

  It also now has `$tries = 5` with backoff 10s / 30s / 2m / 10m. Before, no `$tries` was set, so
  Laravel's default of a single attempt applied -- one failing listener and the delivery was lost.
  Set `XERO_WEBHOOK_TRIES=1` to keep the old behaviour.

  The lock uses your cache store, and `array` and `file` give no cross-process guarantee -- the same
  caveat that already applies to token refresh locking.

### Upgrade notes

**Nothing is required.** A consumer who runs `composer update` and nothing else gets no new tables
and no behaviour change beyond the webhook retry above. All three tables ship as migration stubs,
are published deliberately, and every recorder stays inert until both its flag and its table exist.

To switch any of it on:

```bash
php artisan vendor:publish --tag=xero-bridge-migrations
php artisan vendor:publish --tag=myinvois-migrations
php artisan migrate
```

See [Persistence](docs/09-persistence.md).

## [1.3.0] - 2026-09-29

Adds an optional Malaysian tax-authority client. Nothing existing changes behaviour and nothing is
required of you: the module ships disabled, so for everyone outside Malaysia this release is inert.

### Added

- **An optional LHDN MyInvois taxpayer TIN validator, for Malaysia.** It answers one question --
  is this TIN genuinely paired with this business registration number, NRIC, passport or army
  number in HASiL's records? -- and answers it with a boolean.

  ```php
  use Peoplelogy\XeroBridge\MyInvois\Facades\MyInvois;
  use Peoplelogy\XeroBridge\MyInvois\IdType;

  $valid = MyInvois::validate('C25845632020', IdType::BRN, '201901234567');
  ```

  **Nothing existing changes behaviour.** The module ships **disabled**: it registers no route,
  requires no configuration, adds no startup cost and does not appear in `xero-bridge:status` or
  the test console. If you are not in Malaysia, upgrading changes nothing for you.

  To switch it on, set `MYINVOIS_ENABLED=true` with a client id and secret from the MyInvois
  portal's ERP registration, then `php artisan config:clear`. Settings live in their **own**
  `config/myinvois.php` with its **own** `myinvois-config` publish tag, so republishing either
  config can never overwrite the other.

  Two things worth knowing before you use it. Since **1 August 2026** LHDN validates the TIN and
  the identifier *as a pair*, so you must pass both and a valid TIN with a stale registration
  number now fails exactly like a fabricated one. And a positive answer proves the pair exists in
  HASiL's records and nothing more -- the endpoint returns no name and no address, so it is not
  identity verification.

  A negative answer is **not** an exception: HTTP 404 means "no such pair", which is the answer you
  asked for, so it comes back as `false`. Everything else throws `MyInvoisException`, which carries
  LHDN's error envelope and two predicates, `isRetryable()` and `isConfigurationProblem()`. It is
  deliberately not a `XeroBridgeException`, so `catch (XeroBridgeException)` around your Xero work
  cannot swallow an LHDN fault.

  Access tokens are cached, which is mandatory rather than an optimisation -- LHDN allows only 12
  token requests per minute. Validation results are **not** cached by default; two asymmetric TTLs
  are available if you want them.

  See [the MyInvois documentation](docs/08-myinvois-tin-validation.md).

- **Two test console actions**, `myinvois.validate` and `myinvois.forget_token`, with a panel for
  running a validation by hand. Both appear only while the module is enabled.

### Changed

- **`composer.json`'s description now names both APIs.** The package is still overwhelmingly a Xero
  wrapper, but a Malaysian tax-authority client living inside it should be discoverable from
  `composer show` rather than a surprise.

## [1.2.0] - 2026-09-29

Adds a test console to every application that installs the package. Nothing existing changes behaviour:
the upgrade is `composer update` and, if you want it reachable on a production host or narrower than
`web,auth`, two environment keys.

### Added

- **A test console at `/xero/console`.** Every application that installs the package now gets a page for
  exercising the bridge by hand: configuration health, a pre-flight check of the consent flow and the
  token-refresh lock, every stored connection with its expiry and scopes, the reference lookups you need
  before a first invoice, and the contact, invoice, payment and email flows — each rendered as JSON with
  timings and Xero's rate-limit headers.

  Nothing to publish and no build step. It renders as a standalone HTML document, so it looks and
  behaves the same in an Inertia app, a Livewire app or an API-only one, and cannot disturb your own
  styling.

  **It is on in every environment except production.** Set `XERO_CONSOLE_ENABLED=true` to allow it on a
  production host, or `=false` to remove it everywhere. The route is registered only when the console is
  enabled *and* re-checks the flag on every request, so a `route:cache` built on another box cannot
  leave the page reachable on a live deployment.

  The default middleware is `web,auth`, which means any authenticated user — narrow it with
  `XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"`.

  **Writes are refused unless the connected organisation is disposable.** A Xero Demo Company always is;
  anything else has to be named in `XERO_CONSOLE_WRITABLE_ORGANISATIONS`, matched on the full name and
  case-insensitively, never as a substring. The list is empty by default, so out of the box the console
  can only write into a Demo Company.

  Access tokens, refresh tokens and your client secret never reach the page — credentials are reported
  as booleans (`client_secret_set: true`) and nothing else.

  See [the console documentation](docs/07-test-console.md).

- **The console view is publishable**, with `php artisan vendor:publish --tag=xero-bridge-views`, for
  hosts that need to change the markup. A strict `Content-Security-Policy` is the usual reason: the page
  carries its CSS and JS inline.

- **`xero-bridge:install` now prints the console URL**, and a reminder to narrow its middleware.

### Changed

- **`xero-bridge:status` now shares its reporting with the console**, through a new
  `Support\Diagnostics`. Its rendered output and its exit codes are unchanged.

  Each connection in the `--json` payload gains three keys: `tenant_type`, `usable` and
  `last_failure_at`. Existing keys keep their names, values and order, so anything reading the payload
  by key is unaffected.

## [1.1.0] - 2026-09-28

Renames three configuration keys and removes a dangerous default. Both changes are visible in
`config/xero-bridge.php`, so this is a minor release rather than a patch — but the upgrade is two
renames in your environment file and nothing else.

### Changed

- **`XERO_DEFAULT_ACCOUNT_CODE` → `XERO_ACCOUNT_CODE`**, `XERO_DEFAULT_TAX_TYPE` → `XERO_TAX_TYPE`,
  `XERO_DEFAULT_CURRENCY` → `XERO_CURRENCY`. The old names read as "the default account code" when they
  actually set values on the connection named `default`, which misled people into thinking a rename of
  `XERO_DEFAULT_CONNECTION` would move them. `XERO_DEFAULT_CONNECTION` itself is unchanged, because
  there the word genuinely means "which connection is the default".

  **To upgrade:** rename those three keys wherever you set them. A key left under the old name is
  silently ignored, so check before deploying.

### Removed

- **The `'200'` default for `account_code`.** It was Xero's demo-company sales account and meaningless
  anywhere else: one organisation's sales account may be `200`, another's `4000`, another's
  `410002-001`.

  The danger was that it failed *quietly*. With a default present, an application that never configured
  an account code still produced invoices — posted to whatever account `200` happens to be in that
  organisation, or to nothing recognisable — and Xero accepted them. Nobody finds out until Finance
  reconciles.

  With no default the package sends no `AccountCode`, Xero rejects the invoice, and the resulting
  `XeroValidationException` names the problem. `XERO_CURRENCY` keeps its `MYR` default, because a wrong
  currency is visible on the invoice immediately rather than months later in the ledger.

  **To upgrade:** set `XERO_ACCOUNT_CODE` before creating invoices. Find the valid codes for your
  organisation with `XeroBridge::settings()->accounts()`.

## [1.0.6] - 2026-09-28

Documentation only.

### Added

- An [Updating](README.md#updating) section. It was missing: the README covered installing and
  uninstalling but never said how to move to a newer release.

  The command is `composer update peoplelogy/laravel-xero-bridge` — naming the package matters, because a
  bare `composer update` re-resolves the whole dependency graph and can fail for reasons that have
  nothing to do with this package. Also covers `--with-dependencies` for when a release raises one of the
  package's own requirements.

## [1.0.5] - 2026-09-28

Documentation only. The repository is now public, so installation no longer needs any authentication.

### Changed

- **Installation is now two steps and needs no credentials.** Add the `repositories` entry, then
  `composer require`. Roughly 130 lines of private-repository workarounds are gone, because every one of
  them existed only to get Composer past authentication:
  - the `"no-api": true` flag on the repository entry
  - the per-package `preferred-install` override
  - the whole "Authentication" step (SSH keys versus tokens)
  - the "Why those two extra settings" explanation
  - the deployment section on deploy keys and `COMPOSER_AUTH`

  The repository URL is now the HTTPS form. The uninstall steps no longer mention reverting
  `preferred-install`.

## [1.0.4] - 2026-09-28

Documentation only.

### Added

- A [deployment section](README.md#deploying-server-and-ci-authentication) covering the failure every
  consuming team will hit on their first server install — `Cloning failed using an ssh key for
  authentication`, followed by a prompt for a GitHub token.

  A developer's laptop authenticates to GitHub with their own SSH key; a server does not. This happens
  even when `composer.lock` is committed, because the lock pins *which commit* to install without
  granting access to fetch it.

  The section gives a read-only **deploy key** as the durable answer, with a `COMPOSER_AUTH` token for
  containers and ephemeral CI, plus the two traps that produce the identical prompt afterwards: one
  deploy key may only ever be attached to one repository across an account, and Composer must run as the
  user whose home directory holds the key.

## [1.0.3] - 2026-09-28

Documentation only.

### Added

- An [Uninstalling](README.md#uninstalling) section covering both cases: a bare `composer require`, and a
  full install where the config and migration were published. It spells out that the migration must be
  rolled back **before** the package is removed, while the class is still autoloadable, and that
  `--step=1` is only safe when nothing has been migrated since.

  It also warns that dropping `xero_connections` does not disconnect anything: the table holds encrypted
  OAuth tokens, and deleting them only makes your application forget the connection. Xero still lists the
  application against that organisation and still counts it against the connection limits, so the
  disconnect (`DELETE /connections/{id}`, or the revocation endpoint) has to happen first — while the
  tokens are still readable.

## [1.0.2] - 2026-09-28

Documentation and metadata only. No code changed, so upgrading from 1.0.1 is a no-op — but the
installation instructions in 1.0.0 and 1.0.1 were incomplete and would have failed for anyone following
them, which is what this release corrects.

### Fixed

- **The documented installation steps did not work.** Installing a private Composer repository needs two
  settings that the README omitted, and neither failure message names its real cause:
  - `"no-api": true` on the repository entry. Composer's GitHub driver reads metadata from the GitHub
    API even for an SSH URL, and a private repository's API needs a token — so Composer prompted for one
    that nobody should have needed to create.
  - A per-package `"preferred-install": {"peoplelogy/laravel-xero-bridge": "source"}` override. Even with
    `no-api`, the recorded `dist` URL is a GitHub API zipball that also needs a token, and GitHub answers
    404 rather than 403. Applications that set `"preferred-install": "dist"` — which Laravel's skeleton
    does — forbid the fallback to a git clone, so the install died after the lock file had been written.

  Both are now documented in the README and in [`docs/`](docs/01-getting-started.md), with the exact error
  each one prevents, and the steps were verified end to end from a clean Composer home with no token.
- `composer.json` declared `"license": "MIT"` while `LICENSE.md` states the package is proprietary and for
  internal use. The machine-readable field was the wrong one; it now reads `proprietary`.

### Changed

- The README's Laravel 11 warning now covers the consumer-side consequence: `composer require` makes a
  minimal change and normally succeeds, but a full `composer update` re-resolves `laravel/framework` and
  can be refused outright because of the unfixed advisories against the 11.x line.

## [1.0.1] - 2026-09-28

Bug-fix release. Upgrade from 1.0.0 is a drop-in: no configuration changes, no
constraint changes, nothing to migrate.

### Fixed

- **`XeroBridge::withDefaults()` silently misapplied overrides, in both directions.** Resources are
  memoised per connection with their defaults baked in at construction, and the memo key did not
  include the overrides. If a resource had already been resolved the override was dropped; if the
  overridden call resolved first, the override leaked into every later plain call on that connection
  — putting the wrong account code on an unrelated invoice with nothing failing. An override now
  builds a throwaway instance so it is scoped to the expression that asked for it. The no-override
  path still memoises.
- **`XeroBridge::redirectAfterConnectUsing()` did nothing.** The hook existed on the manager and the
  OAuth callback controller never consulted it, so a registered callback was silently ignored. It is
  now consulted first, receives the stored `XeroConnection` (`null` on the failure path), and falls
  through to the configured destination when it returns nothing usable.
- **Laravel 11 could not install at all.** `testbench.yaml` carried a `laravel: '@testbench'` path
  alias that only Testbench 10+ resolves; on Testbench 9 it reached Laravel's `PackageManifest`
  unresolved and failed `composer install` itself, via the `post-autoload-dump` script.
- **Token expiry was computed against the wrong clock on Carbon 2.** `CarbonImmutable::now()` keeps
  test-now state separate from `Carbon` on Carbon 2, which Laravel 11 still permits. Applications
  that froze time in their tests got wrong expiry answers, and `xero-bridge:refresh-tokens` could
  report a stale token as "still fresh" and refresh nothing. All clock reads now go through
  `Support\Clock`, which reads the framework clock and behaves identically on Carbon 2 and 3.

### Added

- Full developer documentation under [`docs/`](docs/README.md): getting started, invoices and the
  filter builder, contacts/payments/settings, webhooks and events, commands and errors, and worked
  recipes — with request and response samples taken from Xero's own API specification.

### Changed

- **Laravel 11 is now best-effort, not fully supported.** It reached end of security support on
  12 March 2026 and three unfixed advisories affect the whole 11.x line, so Composer will not install
  it under its default advisory policy. The package still works there and CI still exercises it, but
  those legs no longer gate the build. The `illuminate/contracts` constraint is unchanged, so nothing
  breaks for existing consumers. Applications on Laravel 11 should upgrade to 12 or 13 — that is a
  security fix for the application, not a requirement of this package.
- Corrected two inaccurate notes in the config comments and README: Xero assigned granular scopes to
  all Web and PKCE apps from March 2026, new and existing alike, rather than only to apps created
  after a cutoff date; and the connection cap is two separate limits — organisations per developer
  tier, and uncertified applications per organisation.

## [1.0.0] - 2026-09-28

> Superseded by 1.0.1, which fixes two silent bugs present in this release. Use `^1.0`, which
> resolves to the newest patch automatically.

### Added

- OAuth 2.0 authorisation-code flow with `GET /xero/connect/{key?}` and `GET /xero/callback`.
- Multi-organisation support. Connections are stored in `xero_connections` with both tokens encrypted
  at rest and excluded from serialisation.
- Automatic token refresh, guarded by a per-connection cache lock because Xero rotates refresh tokens.
  Transient failures never alter stored credentials; only a genuine `invalid_grant` marks a connection
  as needing re-authorisation.
- HTTP client with per-tenant headers, retries honouring `Retry-After`, and a ceiling above which a
  wait is surfaced as `retryAfter()` rather than blocking a worker.
- `Invoices`: create, createMany, find, list, all (lazy, paged via Xero's pagination object), update,
  authorise, void, delete, email, pdf, onlineUrl, markAsSent.
- `InvoiceFilter` fluent builder.
- `Contacts`: find, findByEmail, findAllByEmail, create (create-only PUT), update, firstOrCreate.
- `Payments`: create, createForInvoice, find, list, delete. Deliberately no update — Xero payments
  cannot be modified.
- `Settings` (read-only): accounts, paymentAccounts, taxRates, organisation, brandingThemes.
- Webhook endpoint with HMAC-SHA256 signature verification, guaranteed cookie-free responses, and
  queued fan-out so listeners cannot breach Xero's 5-second budget.
- Events: `XeroConnected`, `TokenRefreshed`, `ConnectionExpired`, `InvoiceCreated`,
  `XeroWebhookReceived`, `XeroWebhookSignatureFailed`.
- Commands: `xero-bridge:install`, `xero-bridge:status`, `xero-bridge:refresh-tokens`.
- Support for PHP 8.2–8.4 and Laravel 11, 12 and 13.

### Security

- Tokens use the `encrypted` cast **and** are hidden from serialisation, so they cannot leak through
  `toArray()`, a JSON response, an Inertia prop or a logged model.
- Exception messages and `context()` never contain a token or the client secret.
- Invoice writes refuse a `Contact` block carrying anything besides `ContactID`, which Xero would
  otherwise apply to the contact record while deleting omitted `ContactPersons`.
- Invoice updates refuse line items without `LineItemID`, which Xero would otherwise delete and
  recreate.

[Unreleased]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.4.3...v1.5.0
[1.4.3]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/releases/tag/v1.0.0
