# Changelog

All notable changes to `peoplelogy/laravel-xero-bridge` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by hand, in the pull request that makes the change, under `## [Unreleased]`. Write
them for the *consumer*: "`Invoices::create()` now returns X instead of Y", not "refactor invoice DTO".

## [Unreleased]

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

[Unreleased]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/releases/tag/v1.0.0
