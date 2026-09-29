<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Peoplelogy\XeroBridge\Capture\ApiCallRecorder;
use Peoplelogy\XeroBridge\Support\Clock;
use Throwable;

/**
 * LHDN Malaysia (HASiL) MyInvois -- Validate Taxpayer's TIN.
 *
 *     GET {base}/api/v1.0/taxpayer/validate/{tin}?idType={idType}&idValue={idValue}
 *
 * One endpoint, one boolean. The whole module is this class plus a config
 * reader, an enum and an exception.
 *
 * SINCE 1 AUGUST 2026 this endpoint validates the TIN and the identifier AS A
 * PAIR. Before that a loosely-matched pair could pass, so any result cached by
 * an application before that date is untrustworthy -- which is why the result
 * cache key carries a version segment that makes older entries unreadable
 * rather than merely stale.
 *
 * Nothing here shares code with the Xero side, and that is not an oversight.
 * XeroHttpClient is built around per-connection stored tokens, refresh tokens
 * that ROTATE, a replay guarded by the token that failed, and Xero's
 * scope-on-401 semantics. MyInvois is client credentials: one short-lived
 * token, no tenant, nothing rotating. Reusing that machinery would mean
 * carrying its complexity for none of its guarantees.
 */
final class MyInvoisClient
{
    /**
     * Bumped when the MEANING of a cached result changes, not its shape.
     *
     * v2 marks the 1 August 2026 change that made this endpoint validate the
     * TIN and the identifier as a pair. Older entries are not merely stale --
     * they answered a weaker question -- so the version belongs in the key,
     * where it makes them unreadable, rather than in the value where something
     * would have to remember to check it.
     */
    private const RESULT_CACHE_VERSION = 2;

    /** LHDN documents exactly one scope for this API. Not configurable: a wrong scope only breaks auth. */
    private const SCOPE = 'InvoicingAPI';

    /** @var array<string, int|string|null>|null */
    private ?array $lastRateLimit = null;

    /** LHDN's own request id for the last call. Support asks for this. */
    private ?string $lastCorrelationId = null;

    /** The buyer record a verdict is about, when the caller named one. */
    private ?Model $owner = null;

    private ?ApiCallRecorder $recorder = null;

    public function __construct(
        private readonly MyInvoisConfig $config,
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        // Nullable and last, so anything constructing this by hand keeps
        // working across the upgrade.
        private readonly ?MyInvoisAudit $audit = null,
    ) {}

    /**
     * Is this TIN genuinely paired with this identifier in HASiL's records?
     *
     * Returns true on HTTP 200 and false on HTTP 404. The 404 is the ANSWER,
     * not a failure: LHDN documents it as "that TIN and ID combination cannot
     * be found or are considered invalid". Throwing it would force every caller
     * to catch an exception to learn "no".
     *
     * A true answer proves the pair EXISTS in HASiL's records. It does not
     * prove the pair belongs to the counterparty you are invoicing -- the
     * endpoint returns no name, no address and no body at all. Treat it as
     * "this is a real TIN/ID pair", never as identity verification.
     *
     * @throws MyInvoisException on a malformed request, a credential or
     *                           entitlement problem, a rate limit, or an LHDN
     *                           outage -- never for a negative answer.
     */
    public function validate(string $tin, IdType|string $idType, string $idValue): bool
    {
        $this->config->assertUsable();

        $tin = trim($tin);
        $idValue = trim($idValue);
        $type = IdType::coerce($idType);

        // Nothing is sent for a blank input: it can only ever be a 400, and the
        // endpoint's budget is 60 requests per minute.
        if ($tin === '') {
            throw MyInvoisException::missing('tin', 'the $tin argument');
        }

        if ($idValue === '') {
            throw MyInvoisException::missing('idValue', 'the $idValue argument');
        }

        $cacheKey = $this->resultCacheKey($tin, $type, $idValue);

        if ($cacheKey !== null) {
            $cached = $this->cache->store($this->config->resultStore())->get($cacheKey);

            if (is_bool($cached)) {
                return $cached;
            }
        }

        $matched = $this->sendValidate($tin, $type, $idValue);

        // Recorded on BOTH verdicts: "not found" is as much an answer as
        // "found", and an audit that only holds the yeses is not an audit.
        $this->audit?->record(
            $tin,
            $type,
            $idValue,
            $matched,
            $matched ? 200 : 404,
            $this->lastCorrelationId,
            $this->owner,
        );

        $this->rememberResult($cacheKey, $matched);

        return $matched;
    }

    /**
     * The rate limit LHDN reported on the last call, or null if none has been
     * made this request.
     *
     * Key names match the Xero side's RateLimitStatus::toArray() on purpose, so
     * the test console's response bar renders a MyInvois call with no
     * JavaScript changes at all.
     *
     * @return array<string, int|string|null>|null
     */
    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    /**
     * Attach the buyer record this check is about, so the verdict can be found
     * from it later -- and erased with it.
     *
     *     MyInvois::for($customer)->validate($tin, IdType::BRN, $brn);
     */
    public function for(Model $owner): self
    {
        $clone = clone $this;
        $clone->owner = $owner;

        return $clone;
    }

    /**
     * LHDN's correlationId for the last validation, success or not.
     *
     * Worth storing alongside a verdict: it is what LHDN support asks for when
     * you need them to explain an answer, and it cannot be recovered later.
     */
    public function lastCorrelationId(): ?string
    {
        return $this->lastCorrelationId;
    }

    /**
     * Drop the cached access token, forcing the next call to acquire a new one.
     *
     * Exposed because a rotated client secret otherwise leaves a valid-looking
     * token in the cache until it expires on its own.
     */
    public function forgetToken(): void
    {
        $this->cache->store($this->config->tokenStore())->forget($this->tokenCacheKey());
    }

    private function sendValidate(string $tin, IdType $type, string $idValue): bool
    {
        $response = $this->get($this->accessToken(), $tin, $type, $idValue);

        // Exactly one replay, and only after discarding the token that failed.
        // Straight-line, not a loop: if a freshly-minted token is also rejected
        // the credentials are not entitled to this API, and retrying is how you
        // get a Client ID blocked.
        if ($response->status() === 401) {
            $this->forgetToken();

            $response = $this->get($this->accessToken(), $tin, $type, $idValue);
        }

        $this->lastRateLimit = $this->readRateLimit($response);

        // Captured on the VERDICT paths too, not only when building an
        // exception. correlationId is the one id LHDN support asks for, and
        // reading it only on failure threw it away for every answer that
        // actually meant something.
        $this->lastCorrelationId = $response->header('correlationId') ?: null;

        if ($response->status() === 200) {
            return true;
        }

        if ($response->status() === 404) {
            return false;
        }

        throw MyInvoisException::fromResponse($response);
    }

    private function get(string $token, string $tin, IdType $type, string $idValue): Response
    {
        $headers = ['Accept' => 'application/json'];

        // Intermediaries only. LHDN rejects it from anyone not registered as
        // one, so it is sent only when the host has deliberately set it.
        if (($onBehalfOf = $this->config->onBehalfOf()) !== null) {
            $headers['onbehalfof'] = $onBehalfOf;
        }

        $query = ['idType' => $type->value, 'idValue' => $idValue];
        $url = $this->config->validateUrl($tin);
        $started = hrtime(true);

        try {
            $response = $this->request()
                ->withToken($token)
                ->withHeaders($headers)
                ->get($url, $query);
        } catch (Throwable $e) {
            $this->capture('myinvois.api', 'GET', $url, $headers, $query, null, $started, null, $e);

            throw $e;
        }

        $this->capture('myinvois.api', 'GET', $url, $headers, $query, null, $started, $response, null);

        return $response;
    }

    /**
     * Hand one call to the capture table, if the host turned it on.
     *
     * The TIN sits in the URL PATH here, which no key rule can reach. The
     * redactor's URL patterns mask it, and the same patterns are applied to any
     * exception message, because a client exception quotes the URL it failed on.
     *
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function capture(
        string $channel,
        string $method,
        string $url,
        array $headers,
        array $query,
        ?array $body,
        float $started,
        ?Response $response,
        ?Throwable $error,
    ): void {
        $this->recorder ??= app(ApiCallRecorder::class);

        $this->recorder->record(
            channel: $channel,
            method: $method,
            url: $url,
            requestHeaders: $headers,
            requestQuery: $query,
            requestBody: $body,
            response: $response,
            error: $error,
            startedAt: $started,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    */

    /**
     * A cached client-credentials token.
     *
     * Caching is MANDATORY, not an optimisation: the token endpoint allows 12
     * requests per minute per Client ID, and LHDN names "acquiring a new
     * authentication token with every API call" as an anti-pattern. A loop over
     * 50 taxpayers without this is blocked before the tenth.
     *
     * NO LOCK is taken, unlike the Xero side. There is no refresh token to
     * rotate, so two processes acquiring concurrently both receive a valid
     * token and the second simply overwrites the first. The worst case is one
     * wasted request out of twelve; TokenManager's entire locking apparatus
     * exists to prevent a lost refresh token, which cannot happen here.
     */
    private function accessToken(): string
    {
        $store = $this->cache->store($this->config->tokenStore());
        $key = $this->tokenCacheKey();

        $cached = $store->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $form = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config->clientId(),
            'client_secret' => $this->config->clientSecret(),
            'scope' => self::SCOPE,
        ];

        $started = hrtime(true);

        // Here the secret is in the BODY, not a header, so it reaches the
        // recorder and is removed there. RedactionPolicy::ALWAYS holds it,
        // which no configuration can undo.
        $response = $this->request()->asForm()->post($this->config->tokenUrl(), $form);

        $this->capture('myinvois.token', 'POST', $this->config->tokenUrl(), [], [], $form, $started, $response, null);

        if (! $response->successful()) {
            throw MyInvoisException::tokenRejected($response);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw MyInvoisException::tokenRejected($response);
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        // Leeway is taken off the token's own lifetime, so it cannot expire in
        // flight between this process reading it and LHDN checking it.
        $ttl = max(1, $expiresIn - $this->config->tokenLeeway());

        $store->put($key, $token, Clock::now()->addSeconds($ttl));

        return $token;
    }

    /**
     * Hashes the credentials AND the base URL, which closes three holes at once:
     * a rotated secret cannot be served a token minted under the revoked one, a
     * sandbox token can never be served to production, and no credential sits
     * in plaintext in the cache store.
     */
    private function tokenCacheKey(): string
    {
        return 'myinvois:token:'.hash('sha256', implode('|', [
            $this->config->baseUrl(),
            $this->config->clientId(),
            $this->config->clientSecret(),
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Result cache
    |--------------------------------------------------------------------------
    */

    /** Null when caching is off for both outcomes, so nothing is even looked up. */
    private function resultCacheKey(string $tin, IdType $type, string $idValue): ?string
    {
        if ($this->config->matchedTtl() === 0 && $this->config->unmatchedTtl() === 0) {
            return null;
        }

        // The TIN and the identifier are personal data; they are hashed so they
        // cannot surface in a MONITOR stream, a slow log or a cache dump.
        return sprintf(
            'myinvois:tin:v%d:%s',
            self::RESULT_CACHE_VERSION,
            hash('sha256', implode('|', [$this->config->baseUrl(), $tin, $type->value, $idValue])),
        );
    }

    private function rememberResult(?string $key, bool $matched): void
    {
        if ($key === null) {
            return;
        }

        // Asymmetric on purpose: a "yes" is a durable fact about the world, a
        // "no" is usually a typo the user is about to correct.
        $ttl = $matched ? $this->config->matchedTtl() : $this->config->unmatchedTtl();

        if ($ttl === 0) {
            return;
        }

        $this->cache->store($this->config->resultStore())
            ->put($key, $matched, Clock::now()->addSeconds($ttl));
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    private function request(): PendingRequest
    {
        return $this->http
            ->timeout($this->config->timeout())
            ->connectTimeout($this->config->connectTimeout())
            // Connection-level failures and 5xx only. A 400 is our own bad
            // request, a 404 is an answer, and a 429 belongs to the caller's
            // queue rather than an inline sleep that pins a worker -- so
            // throw:false keeps every status in our own hands.
            ->retry(
                $this->config->retries(),
                $this->config->retryBaseMs(),
                static fn ($exception, $request) => $exception instanceof ConnectionException,
                throw: false,
            );
    }

    /**
     * LHDN returns these on every response, not only on a 429.
     *
     * @return array<string, int|string|null>|null
     */
    private function readRateLimit(Response $response): ?array
    {
        $int = static function (?string $value): ?int {
            return ($value === null || $value === '' || ! ctype_digit(trim($value)))
                ? null
                : (int) trim($value);
        };

        $remaining = $int($response->header('X-Rate-Limit-Remaining') ?: null);
        $limit = $int($response->header('X-Rate-Limit-Limit') ?: null);
        $reset = $response->header('X-Rate-Limit-Reset') ?: null;
        $retryAfter = $int($response->header('Retry-After') ?: null);

        if ($remaining === null && $limit === null && $reset === null && $retryAfter === null) {
            return null;
        }

        return [
            // Named to match the Xero side, so the console renders it unchanged.
            'minute_remaining' => $remaining,
            'limit' => $limit,
            'reset' => $reset,
            'problem' => $response->status() === 429 ? 'rate limit' : null,
            'retry_after' => $retryAfter,
        ];
    }
}
