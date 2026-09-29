<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | OFF by default, and that default is load-bearing. This module talks to the
    | Malaysian Inland Revenue Board (LHDN / HASiL) and is of no use to anybody
    | outside Malaysia, so upgrading this package must never add a route, a
    | startup cost, or a nag about settings most consumers will never fill in.
    |
    | Nothing here touches the network until this is true. Calling the client
    | while it is false throws immediately, naming this key -- a far better
    | failure than a 401 six weeks later that nobody can place.
    |
    */

    'enabled' => (bool) env('MYINVOIS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | 'sandbox' or 'production'. The identity service lives on the SAME host as
    | the API in each environment, so this one switch moves the token call and
    | the validate call together and they can never disagree.
    |
    | Sandbox and production issue SEPARATE Client IDs. An ID that works in one
    | is rejected by the other, so changing this without also changing the two
    | credentials below is a misconfiguration, not a promotion.
    |
    */

    'environment' => env('MYINVOIS_ENVIRONMENT', 'sandbox'),

    'base_urls' => [
        'production' => env('MYINVOIS_PRODUCTION_URL', 'https://api.myinvois.hasil.gov.my'),
        'sandbox' => env('MYINVOIS_SANDBOX_URL', 'https://preprod-api.myinvois.hasil.gov.my'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Issued per environment in the MyInvois portal under the taxpayer's ERP
    | registration. Deliberately NO defaults: a wrong-but-present value turns a
    | misconfiguration into an opaque 401 hours later, while a missing one
    | throws at the point of use with a message naming the key.
    |
    | This matters more here than it does for Xero. LHDN AUTO-BLOCKS a Client ID
    | that sends placeholder or blank values to the token endpoint -- their own
    | integration guidance names "0" and "YOUR_CLIENT_ID" -- so a copy-pasted
    | example does not merely fail, it can cost you the ID. The client therefore
    | refuses to send an obvious placeholder at all, and says so locally before
    | a single byte leaves the process.
    |
    | Matching is EXACT (trimmed, case-insensitive), never a substring test: a
    | legitimate high-entropy secret can easily contain "0" or "xxx", and
    | locking someone out of a working integration is worse than the guard.
    |
    */

    'client_id' => env('MYINVOIS_CLIENT_ID'),

    'client_secret' => env('MYINVOIS_CLIENT_SECRET'),

    /*
    | INTERMEDIARIES ONLY. A tax agent acting for another taxpayer sends that
    | taxpayer's identity in the `onbehalfof` header, as a TIN or as TIN:BRN
    | colon-separated. Leave null if you act for yourself, which is almost
    | everybody -- sending it when you are not a registered intermediary is
    | rejected.
    */

    'on_behalf_of' => env('MYINVOIS_ON_BEHALF_OF'),

    /*
    |--------------------------------------------------------------------------
    | Access token
    |--------------------------------------------------------------------------
    |
    | CACHING IS MANDATORY, not an optimisation. The token endpoint allows 12
    | requests per minute per Client ID, and LHDN names "acquiring a new
    | authentication token with every API call" as an anti-pattern in its own
    | integration guidance. A loop validating 50 taxpayers without a cached
    | token is blocked before it reaches the tenth.
    |
    | There is no refresh token: the client_credentials grant is simply re-run.
    | Because nothing ROTATES, two processes acquiring a token at the same
    | moment both get a valid one -- so unlike the Xero side, no cache lock is
    | needed and none is taken. That deletes the entire class of problem that
    | TokenManager exists to solve.
    |
    | `leeway` is subtracted from the token's own expires_in (3600) before it is
    | cached, so a token cannot expire in flight between this process reading it
    | and LHDN checking it.
    |
    */

    'token' => [
        'store' => env('MYINVOIS_TOKEN_CACHE_STORE'),   // null => the default cache store

        'leeway' => (int) env('MYINVOIS_TOKEN_LEEWAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation result cache
    |--------------------------------------------------------------------------
    |
    | OFF by default (0 seconds). LHDN publishes no TTL for a validation RESULT
    | while also pushing ERP-side caching, so the revalidation policy is yours
    | and therefore has to be chosen deliberately.
    |
    | Why off by default: a cached "yes" is a compliance hazard in a way a
    | cached "no" is not. A counterparty can be deregistered between your cache
    | write and your invoice. The two TTLs are asymmetric on purpose -- a "yes"
    | is a durable fact about the world, a "no" is usually a typo the user is
    | about to correct, so it should expire fast or not be cached at all.
    |
    | Turn them on for a bulk reconciliation: the endpoint allows 60 requests
    | per minute per Client ID, which a nightly sweep over a customer table will
    | exhaust immediately.
    |
    | Note that cache keys hash the TIN and the ID value. They are personal
    | data and must not sit in plaintext in Redis, a MONITOR stream or a slow
    | log.
    |
    */

    'results' => [
        'matched_ttl' => (int) env('MYINVOIS_RESULT_MATCHED_TTL', 0),      // seconds; 0 => never cache a "yes"

        'unmatched_ttl' => (int) env('MYINVOIS_RESULT_UNMATCHED_TTL', 0),  // seconds; 0 => never cache a "no"

        'store' => env('MYINVOIS_RESULT_CACHE_STORE'),                     // null => the default cache store
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | `retries` is the TOTAL number of attempts, matching Laravel's Http::retry()
    | and the Xero side of this package. It covers connection-level failures and
    | 5xx ONLY:
    |
    |   400 is our own malformed request -- retrying sends the same bad request.
    |   404 is the ANSWER "no such TIN/ID pair" -- there is nothing to retry.
    |   429 carries Retry-After and belongs to the caller's queue, not to an
    |       inline sleep that pins a worker.
    |
    */

    'http' => [
        'timeout' => (int) env('MYINVOIS_HTTP_TIMEOUT', 30),

        'connect_timeout' => (int) env('MYINVOIS_HTTP_CONNECT_TIMEOUT', 10),

        'retries' => (int) env('MYINVOIS_HTTP_RETRIES', 3),

        'retry_base_ms' => (int) env('MYINVOIS_HTTP_RETRY_BASE_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | There is deliberately no TLS verification switch
    |--------------------------------------------------------------------------
    |
    | LHDN's own SDK documentation walks users through disabling SSL
    | verification when they hit "self signed certificate in certificate chain"
    | (cURL error 60). From PHP that is `verify => false`, and it turns a
    | statutory tax integration into one that accepts any certificate on the
    | path. This package will not offer that switch, so it cannot be reached
    | for at 5pm on a deadline.
    |
    | The actual fix for cURL error 60 is one of:
    |
    |   - point php.ini's `curl.cainfo` and `openssl.cafile` at a current
    |     cacert.pem, which is usually the whole problem on a stock XAMPP,
    |     MAMP or Windows PHP;
    |   - or, if your network inspects TLS, add that proxy's root CA to the
    |     system trust store so the chain genuinely validates.
    |
    */

];
