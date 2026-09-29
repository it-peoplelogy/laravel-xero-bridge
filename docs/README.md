# laravel-xero-bridge documentation

Reference for developers installing this package into a Laravel application.

The [project README](../README.md) is the overview; these pages are the detail.

| Page | What it covers |
|---|---|
| [1. Getting started](01-getting-started.md) | Installing from the private repository, configuration, every `.env` key, creating your Xero app, connecting an organisation, multiple organisations |
| [2. Invoices](02-invoices.md) | Every method on `invoices()`, the `InvoiceFilter` builder, connection defaults, the safety guards, paging |
| [3. Contacts, payments and settings](03-contacts-payments-settings.md) | `contacts()`, `payments()`, `settings()` — find-or-create, applying payments, discovering account codes and tax rates |
| [4. Webhooks and events](04-webhooks-and-events.md) | The webhook endpoint, signature verification, and the six events the package dispatches |
| [5. Commands, errors and token lifecycle](05-commands-and-errors.md) | The three Artisan commands, scheduling token refresh, the exception hierarchy, rate limits |
| [6. Recipes](06-recipes.md) | Complete worked examples, including queued invoice creation and the payment-time buyer-details flow |
| [7. The test console](07-test-console.md) | The page at `/xero/console` that ships with the package — turning it on and off, who can reach it, the write guard, and every action |
| [8. MyInvois TIN validation](08-myinvois-tin-validation.md) | Optional, off by default: validating a Malaysian taxpayer's TIN against LHDN's MyInvois API |
| [9. Persistence](09-persistence.md) | Optional, off by default: duplicate protection for writes, durable webhook replay dedupe, and the MyInvois verdict record |
| [10. API call capture](10-api-capture.md) | Optional, off by default: every Xero and LHDN request and response recorded into a table your own dashboard can read, with bank details and credentials removed before the insert |

Alongside them, one document written to be **circulated** rather than browsed:

| Document | What it is |
|---|---|
| [Using peoplelogy/laravel-xero-bridge](xero-bridge-usage.md) | A single-file practical reference for the six flows most applications need — the calling mechanism, each flow's wire payload and response, error handling. Also built as `.html` and `.pdf` for people who will not clone this repository. |

Regenerate those two after editing the Markdown:

```bash
php docs/build-xero-bridge-usage.php     # -> xero-bridge-usage.html

# then, for the PDF (Chrome writes the file and hangs; background it and
# verify with pdfinfo rather than waiting for it to exit)
chrome --headless --no-pdf-header-footer \
  --print-to-pdf=docs/xero-bridge-usage.pdf \
  file://$PWD/docs/xero-bridge-usage.html
```

The masthead version is read from the document's own version note, so the two cannot disagree — the
build fails rather than produce a mislabelled file.

## Start here

If you are wiring this into an application for the first time, read
[Getting started](01-getting-started.md) end to end, then jump to
[Recipes](06-recipes.md) and adapt the example closest to your use case.

## Things that catch people out

These come up repeatedly, so they are worth knowing before you write any code.

1. **Account codes and tax types differ per Xero organisation.** A `200` sales account in one
   organisation may be `4000` in another. Never hardcode them — read them with
   `settings()->accounts()` and `settings()->taxRates()`. See
   [Contacts, payments and settings](03-contacts-payments-settings.md).

2. **Send only `ContactID` in an invoice's `Contact` block.** Anything else is applied to the
   *contact record* and deletes any `ContactPersons` you left out. The package refuses this by
   default. See [Invoices](02-invoices.md).

3. **Idempotency keys last six minutes.** They protect an immediate network retry, not a queued job
   retried later. Persist the returned `InvoiceID` as your real defence against duplicates. See
   [Recipes](06-recipes.md).

4. **Xero's invoice email takes no CC parameter.** The request body is empty. People are copied by
   adding `ContactPersons` with `IncludeInEmails` to the *contact*. See
   [Contacts, payments and settings](03-contacts-payments-settings.md).

5. **Scopes are fixed at authorisation time.** If you add a scope, deploy it *before* anyone
   reconnects, or they will have to connect twice. See [Getting started](01-getting-started.md).

6. **A MyInvois TIN check needs the BRN too.** Since 1 August 2026 LHDN validates the TIN and the
   identifier as a *pair*, so a valid TIN with a stale registration number fails exactly like a
   fabricated one. See [MyInvois TIN validation](08-myinvois-tin-validation.md).

7. **The test console writes only into a Demo Company.** It appears at `/xero/console` as soon as you
   install the package, in every environment except production. Reads run against whatever is connected,
   but anything that writes is refused unless the organisation is disposable. See
   [The test console](07-test-console.md).

## Conventions in these pages

- Request samples show the PHP you write; response samples show what Xero actually returns.
- Xero responses use PascalCase keys and .NET dates such as `/Date(1439434356790)/`. Use
  `Peoplelogy\XeroBridge\Support\XeroDate` to read them.
- Every credential in an example is a placeholder. Nothing here is a real value.
