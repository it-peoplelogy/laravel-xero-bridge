<?php

declare(strict_types=1);
use Peoplelogy\XeroBridge\Models\XeroConnection;

return [

    /*
    |--------------------------------------------------------------------------
    | Xero application credentials
    |--------------------------------------------------------------------------
    |
    | These deliberately have NO defaults. A wrong-but-present default turns a
    | misconfiguration into an opaque 401 from Xero hours later; a missing value
    | throws XeroConfigurationException at the point of use with a message that
    | names the env key. Create the app at https://developer.xero.com/myapps.
    |
    */

    'client_id' => env('XERO_CLIENT_ID'),

    'client_secret' => env('XERO_CLIENT_SECRET'),

    /*
    | The signing key from the Xero app's Webhooks tab. Leave unset to disable
    | webhook handling entirely.
    */
    'webhook_key' => env('XERO_WEBHOOK_KEY'),

    /*
    | Must match a redirect URI registered on the Xero app EXACTLY, including
    | any trailing slash. When null the package falls back to the callback route
    | at runtime. Xero requires https, except that http://localhost is allowed
    | for testing -- http://127.0.0.1 is explicitly rejected by Xero.
    */
    'redirect_uri' => env('XERO_REDIRECT_URI'),

    /*
    | Space-separated. `offline_access` is mandatory: without it Xero issues no
    | refresh token and the connection dies 30 minutes after it is made.
    |
    | These are Xero's granular scopes. Since March 2026 Xero has assigned
    | granular scopes to all Web and PKCE apps, new and existing alike; the
    | broad scopes they replace (such as accounting.transactions) keep working
    | until September 2027.
    */
    'scopes' => env('XERO_SCOPES', 'openid profile email offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings accounting.attachments'),

    /*
    | The connection used when no name is passed to XeroBridge::connection().
    | Single-organisation apps can ignore connection names entirely.
    */
    'default_connection' => env('XERO_DEFAULT_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Xero endpoints
    |--------------------------------------------------------------------------
    |
    | Overridable only so tests and sandboxes can point elsewhere. There is no
    | reason to change these in an application.
    |
    */

    'endpoints' => [
        'authorize' => env('XERO_AUTHORIZE_URL', 'https://login.xero.com/identity/connect/authorize'),
        'token' => env('XERO_TOKEN_URL', 'https://identity.xero.com/connect/token'),
        'revocation' => env('XERO_REVOCATION_URL', 'https://identity.xero.com/connect/revocation'),
        'connections' => env('XERO_CONNECTIONS_URL', 'https://api.xero.com/connections'),
        'api' => env('XERO_API_URL', 'https://api.xero.com/api.xro/2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The connect/callback routes need a SESSION to carry the OAuth state, so
    | the `web` middleware group (or something else that starts a session) is
    | required. The webhook route is registered separately and deliberately
    | carries no session and no cookies -- see the webhook config below.
    |
    | Note: `php artisan route:cache` bakes in whatever `enabled` was at cache
    | time.
    |
    */

    'routes' => [
        'enabled' => (bool) env('XERO_ROUTES_ENABLED', true),

        'prefix' => env('XERO_ROUTES_PREFIX', 'xero'),

        'name_prefix' => env('XERO_ROUTES_NAME_PREFIX', 'xero-bridge.'),

        // Comma separated, e.g. XERO_ROUTES_MIDDLEWARE="web,auth,can:manage-xero"
        'middleware' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('XERO_ROUTES_MIDDLEWARE', 'web,auth'))
        ))),

        /*
        | Where the callback sends the user once an organisation is connected.
        | A named route wins over a raw path. A closure registered with
        | XeroBridge::redirectAfterConnectUsing() wins over both -- config files
        | cannot hold closures once cached, hence the separate hook.
        */
        'after_connect_route' => env('XERO_AFTER_CONNECT_ROUTE'),
        'after_connect_redirect' => env('XERO_AFTER_CONNECT_REDIRECT', '/'),

        /*
        | Honour ?return_to= on the connect route. Off by default: these are
        | authenticated routes and an unvalidated redirect target is an open
        | redirect. When on, only same-host absolute paths are accepted.
        */
        'allow_return_to' => (bool) env('XERO_ALLOW_RETURN_TO', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test console
    |--------------------------------------------------------------------------
    |
    | A self-contained page at {routes.prefix}/{console.prefix} -- /xero/console
    | by default -- that exercises the bridge by hand: configuration health,
    | stored connections, reference lookups, the invoice and contact flows, a
    | forced token refresh, and every response rendered as JSON with timings
    | and Xero's rate-limit headers.
    |
    | It is ON by default everywhere EXCEPT production, so it is there the
    | moment the package is installed without ever being a surprise on a live
    | deployment. It renders as a standalone HTML document with no build step,
    | so it works the same in any application.
    |
    | Note: `php artisan route:cache` bakes in whatever was registered at cache
    | time, exactly as it does for `routes.enabled`. The console route also
    | re-checks `enabled` on every request and 404s when it is off, so a cache
    | built elsewhere cannot leave the page reachable in production.
    |
    */

    'console' => [
        /*
        | null  => on everywhere except production
        | true  => on, including production
        | false => off everywhere
        |
        | The environment is deliberately NOT resolved here. A config file is
        | evaluated once, at `config:cache` time, so baking the decision in
        | would freeze whichever environment happened to run that command.
        | Left null, it is resolved at boot instead, on every request.
        |
        | An empty value counts as unset, because a bare `XERO_CONSOLE_ENABLED=`
        | copied out of an example file is not a request to switch it off.
        */
        'enabled' => in_array(env('XERO_CONSOLE_ENABLED'), [null, ''], true)
            ? null
            : filter_var(env('XERO_CONSOLE_ENABLED'), FILTER_VALIDATE_BOOLEAN),

        // Appended to routes.prefix, so the default is /xero/console.
        'prefix' => env('XERO_CONSOLE_PREFIX', 'console'),

        /*
        | Comma separated, like routes.middleware. The console posts a CSRF
        | token, so it needs something that starts a SESSION. Narrow this to
        | your own admin gate wherever you have one, e.g.
        | XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"
        */
        'middleware' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('XERO_CONSOLE_MIDDLEWARE', 'web,auth'))
        ))),

        /*
        | Organisations the console may WRITE into, by exact name, comma
        | separated and matched case-insensitively.
        |
        | A Xero Demo Company is always writable -- Xero provisions it itself,
        | flags it with IsDemoCompany and its data is disposable. Anything else
        | is somebody's real ledger: a DRAFT can be deleted, but an AUTHORISED
        | invoice can only ever be voided and stays visible in Xero for good.
        |
        | A sandbox organisation you create by hand is indistinguishable from a
        | real ledger over the API, which is why it has to be named here. Add
        | one only if you are willing to lose its data.
        */
        'writable_organisations' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('XERO_CONSOLE_WRITABLE_ORGANISATIONS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write ledger
    |--------------------------------------------------------------------------
    |
    | Durable duplicate protection for writes into Xero.
    |
    | Xero honours an idempotency key for only SIX MINUTES, which no realistic
    | queue backoff stays inside -- so a job that retries an hour later creates
    | a SECOND invoice. This ledger is the defence the documentation has always
    | asked consumers to build for themselves: a row claimed before the request
    | leaves, confirmed after it lands, with a UNIQUE index that turns a race
    | between two workers into a database error instead of two invoices.
    |
    | Protection applies to a write that names an owner:
    |
    |     XeroBridge::invoices()->for($order)->create([...]);
    |
    | A write with no owner is still recorded, but cannot be deduplicated --
    | there is nothing to deduplicate it against.
    |
    | OFF by default, and it needs its table published and migrated. A consumer
    | who upgrades and does nothing gets exactly the behaviour they had.
    |
    | ONE RULE. Do not wrap a Xero write in a database transaction. The claim
    | must be committed before the HTTP request, or it is invisible to other
    | workers and a rollback erases the record that the write happened. The
    | package logs a warning if it detects one.
    |
    */

    'writes' => [
        'enabled' => (bool) env('XERO_WRITES_LEDGER', false),

        'table' => env('XERO_WRITES_TABLE', 'xero_write_records'),

        /*
        | How long a SUCCEEDED row is kept, in days, by xero-bridge:prune.
        |
        | Pending rows are NEVER pruned at any age. A pending row means we sent
        | something to Xero and never learned the outcome; deleting it would
        | free the claim and allow the duplicate it exists to prevent. They
        | accumulate visibly on purpose -- that is the pressure to go and look.
        */
        'retain_days' => (int) env('XERO_WRITES_RETAIN_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | API call capture
    |--------------------------------------------------------------------------
    |
    | Records the request and response of every Xero and LHDN call into
    | xero_api_calls, so a consuming project can render them on ITS OWN
    | dashboard. This package deliberately ships no viewer: every project has
    | its own roles and its own idea of who may see customer data, and a
    | package cannot know either.
    |
    | Read the rows through Peoplelogy\XeroBridge\Models\XeroApiCall and its
    | scopes rather than by writing SQL against the table. The model is the
    | supported interface; the columns underneath may change.
    |
    | OFF by default, and it needs its table published and migrated. A consumer
    | who upgrades and does nothing keeps exactly the behaviour they had.
    |
    | THIS IS NOT A LEDGER. The row is written AFTER the response, so a worker
    | killed mid-call leaves no row. "Did that invoice reach Xero?" is answered
    | by xero_write_records, which is inserted BEFORE the request for that very
    | reason. Never answer it from here.
    |
    */

    'capture' => [

        'enabled' => (bool) env('XERO_CAPTURE', false),

        'table' => env('XERO_CAPTURE_TABLE', 'xero_api_calls'),

        /*
        | all    -- every call, reads included
        | writes -- POST/PUT/PATCH/DELETE, plus anything that failed
        | errors -- failures only
        |
        | `writes` is the default because reads are the volume and the flood of
        | GET /Invoices during a sync answers no question a human asks. A FAILED
        | call is captured in every mode, including a failed read: a Xero error
        | can arrive as a 200, so a rule keyed only on the status code would let
        | a rejected batch through as ordinary read traffic.
        */
        'mode' => env('XERO_CAPTURE_MODE', 'writes'),

        /*
        | Channels, switchable one at a time. A host outside Malaysia has no use
        | for the MyInvois rows; a host with a strict credential policy may want
        | the token exchanges off entirely even though every credential in them
        | is redacted.
        */
        'channels' => [
            'xero.api' => (bool) env('XERO_CAPTURE_XERO_API', true),
            'xero.identity' => (bool) env('XERO_CAPTURE_XERO_IDENTITY', true),
            'myinvois.api' => (bool) env('XERO_CAPTURE_MYINVOIS_API', true),
            'myinvois.token' => (bool) env('XERO_CAPTURE_MYINVOIS_TOKEN', true),
        ],

        // Days a row is kept, by xero-bridge:prune. Every row is prunable at
        // its age -- nothing in this table protects against anything, so
        // keeping it past its useful window is a liability, not a safeguard.
        'retain_days' => (int) env('XERO_CAPTURE_RETAIN_DAYS', 90),

        // Per body, AFTER redaction. Beyond this the body is replaced by a
        // marker carrying its size and its top-level keys: a half-written JSON
        // document is worse than none, because nothing can parse it.
        'max_body_bytes' => (int) env('XERO_CAPTURE_MAX_BODY_BYTES', 65536),

        /*
        | Endpoints skipped entirely, matched against the full URL.
        |
        | GET /Invoices/{id}/OnlineInvoice returns exactly one field, and that
        | field is a public unauthenticated capability link: whoever holds it
        | can view the invoice and, with a payment service configured, pay it.
        | Redacting the field would store a row containing nothing, so the right
        | control is to skip the call.
        */
        'skip_urls' => [
            '#/OnlineInvoice(\?|$)#i',
        ],

        'redact' => [

            /*
            | What a removed value becomes. A fixed string, not null and not a
            | deleted key: "we never sent a TaxNumber" and "we sent one and hid
            | it" are different answers to a real support question.
            */
            'placeholder' => env('XERO_CAPTURE_PLACEHOLDER', '[redacted]'),

            /*
            | ADDED to the list shipped in Support\RedactionPolicy::DEFAULTS.
            | Matched EXACTLY and case-insensitively, at any depth, in request
            | and response alike, in bodies, headers and query strings.
            |
            | Deltas rather than a literal list on purpose: a project that
            | published this file today and upgrades in a year would otherwise
            | silently miss every field name added in between.
            |
            | The two most defensible additions for a stricter PDPA posture:
            |     'phonenumber', 'addressline1'
            |
            | And if your organisation types account references into the NZ bank
            | reference fields:
            |     'particulars', 'details'
            */
            'add' => [],

            /*
            | REMOVED from that list.
            |
            | CREDENTIALS AND BANK DETAILS CANNOT BE REMOVED. Naming them here
            | does nothing. Support\RedactionPolicy::ALWAYS wins, because two
            | exceptions promise in writing that nothing this package produces
            | carries a token, and because "save everything except bank account"
            | is a requirement rather than a preference -- neither should be
            | undoable by one line of configuration.
            |
            | What you CAN keep: taxnumber, idvalue, onbehalfof, searchterm,
            | target, xeronetworkkey, onlineinvoiceurl.
            */
            'keep' => [],

            // Walk limits. The deepest real Xero path is six; a request payload
            // built by a host can contain a reference cycle, and the depth cap
            // is what terminates it.
            'max_depth' => 24,
            'max_nodes' => 20000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Xero's "intent to receive" check requires a 2xx within 5 SECONDS and NO
    | cookies in the response. The route is therefore registered with no
    | middleware group at all, and the response is stripped of Set-Cookie.
    |
    | Xero replays events for up to 31 days, so listeners must be idempotent.
    |
    */

    'webhooks' => [
        'enabled' => (bool) env('XERO_WEBHOOKS_ENABLED', true),

        'path' => env('XERO_WEBHOOK_PATH', 'webhook'),

        // The queue the envelope job is pushed onto. The controller never
        // processes events inline; that is what protects the 5-second budget.
        'queue' => env('XERO_WEBHOOK_QUEUE'),

        'connection' => env('XERO_WEBHOOK_QUEUE_CONNECTION'),

        /*
        | How long the uniqueness lock on the envelope job is held, in seconds.
        |
        | The controller dispatches the job BEFORE returning its 200, so if the
        | response misses Xero's five-second budget the job is already queued
        | when Xero retries -- and without a lock every listener fires twice for
        | one logical change. This window covers Xero's retries for a single
        | delivery.
        |
        | The lock is only as good as the cache store: `array` and `file` give
        | no cross-process guarantee, so with several queue workers you want
        | redis, memcached or database here as well.
        */
        'unique_for' => (int) env('XERO_WEBHOOK_UNIQUE_FOR', 900),

        /*
        | Attempts for the envelope job, TOTAL including the first.
        |
        | Before 1.4.0 this was unset, which means Laravel's default of a single
        | attempt: one failing listener and the whole envelope was lost with no
        | retry. Backoff is 10s, 30s, 2m, 10m.
        */
        'tries' => (int) env('XERO_WEBHOOK_TRIES', 5),

        /*
        |----------------------------------------------------------------------
        | Durable replay dedupe
        |----------------------------------------------------------------------
        |
        | The lock above catches a retry storm within minutes. It cannot catch a
        | replay Xero sends DAYS later: undelivered events are stored for up to
        | 31 days and replayed in order once your endpoint recovers.
        |
        | With this on, and the table published and migrated, each event is
        | recorded the first time it is dispatched and skipped on every later
        | delivery. Off means the package behaves exactly as it did before --
        | your listeners must be idempotent themselves, which they should be
        | regardless.
        |
        | `retain_days` is clamped to a minimum of 32: pruning inside Xero's
        | 31-day replay window would delete the very rows that make a late
        | replay detectable, which is the only thing this table is for.
        */
        'dedupe' => [
            'enabled' => (bool) env('XERO_WEBHOOK_DEDUPE', false),

            'table' => env('XERO_WEBHOOK_DEDUPE_TABLE', 'xero_webhook_events'),

            'retain_days' => (int) env('XERO_WEBHOOK_DEDUPE_RETAIN_DAYS', 45),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('XERO_HTTP_TIMEOUT', 30),

        'connect_timeout' => (int) env('XERO_HTTP_CONNECT_TIMEOUT', 10),

        // TOTAL attempts including the first, matching Laravel's Http::retry().
        // 3 means the initial try plus 2 retries.
        'retries' => (int) env('XERO_HTTP_RETRIES', 3),

        'retry_status' => [429, 500, 502, 503, 504],

        'retry_base_ms' => (int) env('XERO_HTTP_RETRY_BASE_MS', 1000),

        /*
        | Hard ceiling on any single inline sleep. Xero's Retry-After on a daily
        | rate limit can be hours; rather than pin a worker, the client throws
        | with retryAfter() so a queued job can release() itself.
        */
        'retry_max_ms' => (int) env('XERO_HTTP_RETRY_MAX_MS', 30000),

        /*
        | Concurrency-limit 429s carry NO Retry-After header at all (only the
        | minute and daily limits do), so they need their own short backoff.
        */
        'concurrency_backoff_ms' => (int) env('XERO_HTTP_CONCURRENCY_BACKOFF_MS', 250),

        // Xero suggests ~5 minutes for "The Organisation is offline" (503).
        'offline_retry_after' => (int) env('XERO_OFFLINE_RETRY_AFTER', 300),

        /*
        | Send an Idempotency-Key on writes. When false, write requests are
        | never retried at all, because a retried POST that actually succeeded
        | creates a duplicate invoice.
        |
        | Note Xero retains a key for only SIX MINUTES. It protects an immediate
        | transient-network retry; it does NOT deduplicate a queued job retried
        | later. Persist the returned InvoiceID for that.
        */
        'idempotency' => (bool) env('XERO_HTTP_IDEMPOTENCY', true),

        // null => "{app.name} (peoplelogy/laravel-xero-bridge)"
        'user_agent' => env('XERO_USER_AGENT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    |
    | Xero access tokens last 30 minutes and refresh tokens ROTATE on every use:
    | refreshing invalidates the token you refreshed with. Two processes
    | refreshing the same connection concurrently will each invalidate the
    | other, so the refresh is wrapped in a per-connection cache lock.
    |
    | The three timings interlock deliberately:
    |     http_timeout (8) < lock_ttl (10) < lock_wait (12)
    | so a holder can never outlive its lock, and a waiter can never give up
    | before a dead holder's lock expires.
    |
    | The lock is only as good as the cache store: `array` and `file` give no
    | cross-process guarantee. Use redis, memcached or database in production.
    |
    */

    'tokens' => [
        // Refresh when the access token expires within this many seconds.
        'refresh_leeway' => (int) env('XERO_REFRESH_LEEWAY', 60),

        'lock_store' => env('XERO_LOCK_STORE'),          // null => default store
        'lock_ttl' => (int) env('XERO_LOCK_TTL', 10),    // seconds a holder may hold
        'lock_wait' => (int) env('XERO_LOCK_WAIT', 12),  // > ttl, so a dead holder always frees

        'http_timeout' => (int) env('XERO_TOKEN_HTTP_TIMEOUT', 8),   // < lock_ttl
        'http_retries' => (int) env('XERO_TOKEN_HTTP_RETRIES', 2),   // network only, never a 4xx
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | A BARE table name. The host's database connection applies its own prefix:
    | pips sets `prefix => 'pips_'` with prefix_indexes on its mysql connection,
    | so its table becomes pips_xero_connections automatically. Never hardcode
    | a prefix here.
    |
    */

    'database' => [
        'connection' => env('XERO_DB_CONNECTION'),   // null => default connection
        'table' => env('XERO_DB_TABLE', 'xero_connections'),
    ],

    'model' => XeroConnection::class,

    /*
    |--------------------------------------------------------------------------
    | Re-authorisation conflict policy
    |--------------------------------------------------------------------------
    |
    | `key` and `tenant_id` are both unique, which creates four collision cases
    | when someone reconnects. These two switches decide them.
    |
    | on_key_conflict    -- the key exists but points at a different Xero org.
    |   'replace' : treat the key as an app-side slot and repoint it (default)
    |   'error'   : refuse, and tell the user to disconnect the old org first
    |
    | on_tenant_conflict -- this org is already connected under a different key.
    |   'error'   : refuse (default). Silently re-keying would break every
    |               caller that already references the old key.
    |   'rekey'   : move the existing row to the new key
    |
    */

    'on_key_conflict' => env('XERO_ON_KEY_CONFLICT', 'replace'),

    'on_tenant_conflict' => env('XERO_ON_TENANT_CONFLICT', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Per-connection invoice defaults
    |--------------------------------------------------------------------------
    |
    | Filled into LineItems[].AccountCode / TaxType and top-level CurrencyCode /
    | BrandingThemeID only where the caller left them out. Named connections
    | inherit from 'default'; an explicit key wins even when set to null.
    |
    | ACCOUNT CODES AND TAX TYPES DIFFER PER XERO ORGANISATION, and they are not
    | interchangeable: one organisation's sales account may be '200', another's
    | '4000', another's '410002-001'. Discover them per tenant with
    | XeroBridge::connection($key)->settings()->accounts() / ->taxRates().
    |
    | account_code and tax_type therefore have NO default. A wrong-but-present
    | account code is worse than a missing one: Xero accepts the invoice and
    | posts it to the wrong ledger account, which nobody notices until Finance
    | reconciles. With no default the package sends no AccountCode and Xero
    | rejects the invoice with a message naming the problem.
    |
    | tax_type is unset for a second reason. Malaysian SST is per LINE, not per
    | invoice -- training is 8% while education and rental/leasing are 6%, so a
    | single invoice can legitimately carry two rates. A connection-wide default
    | would stamp the wrong rate on a venue recharge. Set it only if every line
    | of every invoice on this connection genuinely carries the same rate.
    |
    */

    'connections' => [

        'default' => [
            'account_code' => env('XERO_ACCOUNT_CODE'),
            'tax_type' => env('XERO_TAX_TYPE'),
            'currency' => env('XERO_CURRENCY', 'MYR'),
            'branding_theme_id' => env('XERO_BRANDING_THEME_ID'),
        ],

    ],

];
