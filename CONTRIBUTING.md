# Contributing

```bash
composer test       # Pest
composer analyse    # PHPStan level 5
composer format     # Pint
composer lint       # Pint, check only
composer serve      # boot the workbench app at http://127.0.0.1:8000
```

All three of `test`, `analyse` and `lint` must pass before a pull request.

## Write tests against the Pest 3 API

The CI matrix resolves **Pest 3, 4 or 5** depending on the leg:

| Leg | Pest |
|---|---|
| PHP 8.2 (Laravel 11, 12) | 3 — Pest 4 requires PHP 8.3 |
| PHP 8.3 / 8.4 with Laravel 11 or 12 | 4 — Pest 5 requires `symfony/process ^8.1`, which Laravel 11 and 12 forbid |
| PHP 8.4 with Laravel 13 | 5 |

So **Pest 5 is exercised on exactly one of eight legs**, and anything Pest-4-or-5-only breaks the other
seven. In particular:

- Do not use `arch()->preset()->*`. The preset API changed shape between arch 3 and 4/5 and will pass
  locally while failing in CI.
- Do not use Pest 4's mutation-testing helpers.

None of this is discoverable from a green run on a PHP 8.4 laptop, which is why it is written down.

## Local environment notes

- **`COMPOSER_MEMORY_LIMIT=-1` is required** for any `composer update` here. The default CLI
  `memory_limit` is too small to resolve three floating Pest majors against three Laravel majors, and
  Composer has no `composer.json` equivalent for this.
- **PHP 8.2 cannot be tested locally** unless you install it — the floor of the support window is only
  ever proven in CI. Treat a red 8.2 leg as real, not as a CI quirk.
- **Coverage is CI-only.** With no Xdebug or PCOV installed, `composer test-coverage` reports no
  coverage driver.
- `phpunit.xml.dist` sets `memory_limit=512M`, which a booted Testbench app needs.
- **Delete what an older install test left behind, once.** `xero-bridge:install` really publishes, and
  under Testbench that means into the shared skeleton in `vendor/`. Older runs of its test left
  `vendor/orchestra/testbench-core/laravel/config/xero-bridge.php` and `*_create_xero_*_table.php`
  migrations there, and every later boot loads that stale config in place of the shipped one — locally
  only, since CI starts from a fresh `vendor/`. The tests now clean up after themselves, so this is a
  one-off:
  ```bash
  rm -f vendor/orchestra/testbench-core/laravel/config/xero-bridge.php
  find vendor/orchestra/testbench-core/laravel/database/migrations -name '*_create_xero_*_table.php' -delete
  ```

## Test suite rules

- `Http::preventStrayRequests()` is on for every test. Any URL you forget to fake throws rather than
  reaching Xero.
- The clock is frozen at a fixed instant. Token-expiry maths is unreadable against a moving one.
  **Exception:** anything exercising `Cache::lock()->block()` must unfreeze it first
  (`Carbon::setTestNow()`), because `block()` measures its timeout with `Carbon::now()` and under a
  frozen clock it loops forever instead of timing out.
- The database is in-memory SQLite with an explicitly empty prefix, which proves no query in the
  package assumes one; host applications commonly apply one at the connection level.
- `APP_KEY` is random per boot, so no fixed value can be mistaken for a real credential. Assert that a
  token round-trips, never on its ciphertext.

## The workbench

`composer serve` boots a real Laravel application with the package installed, for manual exploration.

> Point it only ever at a Xero **Demo Company**, never a client's live organisation. Real credentials
> go in `workbench/.env`, which is gitignored. `testbench.yaml` is committed and must stay free of
> secrets.

The workbench has the test console on because `testbench.yaml` sets `XERO_CONSOLE_ENABLED=true`.
Nothing else switches it on — not even `APP_ENV` — so an application that installs the package has no
console until it sets that itself.

Exercising the **MyInvois** panel by hand needs `MYINVOIS_ENABLED=true` plus a sandbox client id and
secret in that same gitignored file. Use `MYINVOIS_ENVIRONMENT=sandbox` and the preprod host — LHDN
issues separate ids per environment, and production is somebody's statutory tax record.

## Releasing

1. Move the `## [Unreleased]` entries under a new version heading with today's date.
2. Commit, then tag an annotated `v`-prefixed tag from `main`:
   ```bash
   git tag -a v1.1.0 -m "v1.1.0"
   git push origin v1.1.0
   ```
3. Cut a GitHub Release pointing at the tag, with the changelog section pasted in.

Tags are not optional: consuming applications set `"minimum-stability": "stable"`, so an untagged
package resolves only to `dev-main` and cannot be installed at all.

Adding a Laravel major is a **minor** release. Dropping a Laravel or PHP major is a **major** one,
because it can break an existing consumer's `composer update`.
