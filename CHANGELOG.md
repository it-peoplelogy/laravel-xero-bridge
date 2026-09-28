# Changelog

All notable changes to `peoplelogy/laravel-xero-bridge` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are written by hand, in the pull request that makes the change, under `## [Unreleased]`. Write
them for the *consumer*: "`Invoices::create()` now returns X instead of Y", not "refactor invoice DTO".

## [Unreleased]

### Fixed

- Laravel 11 compatibility. `testbench.yaml` carried a `laravel: '@testbench'` path alias that only
  Testbench 10+ resolves; on Testbench 9 it reached Laravel's `PackageManifest` unresolved and failed
  `composer install` itself.
- Token expiry was computed with `CarbonImmutable::now()`, which on Carbon 2 keeps test-now state
  separate from `Carbon`. Applications on Carbon 2 (Laravel 11 only) that froze time in their tests
  got wrong expiry answers, and `xero-bridge:refresh-tokens` could report a stale token as "still
  fresh". All clock reads now go through `Support\Clock`, which uses the framework clock.

### Changed

- **Laravel 11 is now best-effort, not fully supported.** It reached end of security support on
  12 March 2026 and three unfixed advisories affect the whole 11.x line, so Composer will not install
  it under its default advisory policy. The package still works there and CI still exercises it, but
  those legs no longer gate the build. See the README. Consumers on Laravel 11 should upgrade to 12
  or 13.

## [1.0.0] - 2026-09-28

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

[Unreleased]: https://github.com/it-peoplelogy/laravel-xero-bridge/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/it-peoplelogy/laravel-xero-bridge/releases/tag/v1.0.0
