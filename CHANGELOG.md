# Changelog

All notable changes to `peoplelogy/laravel-xero-bridge` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by hand, in the pull request that makes the change, under `## [Unreleased]`. Write
them for the *consumer*: "`Invoices::create()` now returns X instead of Y", not "refactor invoice DTO".

## [Unreleased]

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

[Unreleased]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.3.0...HEAD
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
