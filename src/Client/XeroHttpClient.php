<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Client;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Peoplelogy\XeroBridge\Exceptions\XeroAuthenticationException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroScopeException;
use Peoplelogy\XeroBridge\Exceptions\XeroServiceUnavailableException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Support\IdempotencyKey;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every call to the Xero Accounting API goes through here.
 */
final class XeroHttpClient
{
    private ?RateLimitStatus $lastRateLimit = null;

    public function __construct(
        private readonly string $connectionKey,
        private readonly TokenManager $tokens,
        private readonly HttpFactory $http,
        private readonly RetryPolicy $policy,
        private readonly XeroConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array<mixed> */
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $uri, [], $headers, $query);
    }

    /** @return array<mixed> */
    public function post(string $uri, array $payload = [], array $headers = [], array $query = []): array
    {
        return $this->request('POST', $uri, $payload, $headers, $query);
    }

    /** @return array<mixed> */
    public function put(string $uri, array $payload = [], array $headers = [], array $query = []): array
    {
        return $this->request('PUT', $uri, $payload, $headers, $query);
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function request(
        string $method,
        string $uri,
        array $payload = [],
        array $headers = [],
        array $query = [],
    ): array {
        $response = $this->send($method, $uri, $payload, $headers, $query);

        // 204 No Content is a success with nothing to decode -- POST
        // /Invoices/{id}/Email returns exactly that, and calling ->json() on
        // it would turn a successful send into a failure.
        if ($response->status() === 204) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * The raw response, for PDFs and anything else that is not JSON.
     */
    public function send(
        string $method,
        string $uri,
        array $payload = [],
        array $headers = [],
        array $query = [],
        ?string $accept = null,
    ): Response {
        $connection = $this->tokens->valid($this->connectionKey);
        $usedToken = $this->tokens->readToken($connection, 'access_token');

        $response = $this->dispatch($connection, $usedToken, $method, $uri, $payload, $headers, $query, $accept);

        if ($response->status() === 401) {
            // Insufficient scope is ALSO a 401, and refreshing will never fix
            // it. Checked first, so the refresh path can never loop on it.
            if ($this->isInsufficientScope($response)) {
                throw XeroScopeException::make(
                    $this->connectionKey,
                    $this->config->connectUrl($this->connectionKey),
                    $connection->scopeList(),
                )->withResponse($response, $this->connectionKey);
            }

            // Exactly one replay, guarded by the token that actually failed.
            // Straight-line code, not a loop.
            $connection = $this->tokens->refreshBecauseOf($connection, $usedToken);
            $newToken = $this->tokens->readToken($connection, 'access_token');

            $response = $this->dispatch($connection, $newToken, $method, $uri, $payload, $headers, $query, $accept);

            if ($response->status() === 401) {
                throw XeroAuthenticationException::afterRefresh($response, $this->connectionKey);
            }
        }

        $this->recordRateLimit($response);

        if ($response->successful()) {
            return $response;
        }

        throw $this->toException($response);
    }

    public function lastRateLimit(): ?RateLimitStatus
    {
        return $this->lastRateLimit;
    }

    private function dispatch(
        XeroConnection $connection,
        string $accessToken,
        string $method,
        string $uri,
        array $payload,
        array $headers,
        array $query,
        ?string $accept = null,
    ): Response {
        $isWrite = ! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);

        // One key per LOGICAL call, generated before the retry loop, so all
        // attempts of the same call share it.
        if ($isWrite
            && $this->config->get('http.idempotency', true)
            && ! $this->hasHeader($headers, 'Idempotency-Key')
        ) {
            $headers['Idempotency-Key'] = IdempotencyKey::generate();
        }

        $request = $this->pending($connection, $accessToken, $headers, $accept);

        if ($this->policy->retriesMethod($method, $this->hasHeader($headers, 'Idempotency-Key'))) {
            $request = $request->retry(
                $this->policy->times(),
                fn (int $attempt, Throwable $e): int => $this->policy->delayMs($attempt, $e),
                fn (Throwable $e): bool => $this->policy->shouldRetry($e),
                throw: false,
            );
        }

        $url = ltrim($uri, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').$this->buildQuery($query);
        }

        return match (strtoupper($method)) {
            'GET' => $request->get($url),
            'DELETE' => $request->delete($url, $payload),
            'PUT' => $request->put($url, $payload),
            'PATCH' => $request->patch($url, $payload),
            default => $request->post($url, $payload),
        };
    }

    /**
     * $accept is a deliberate, first-class override used by pdf(). It is a
     * separate argument rather than a header because a header in $headers is
     * intentionally ignored -- see the replaceHeaders() note below.
     */
    private function pending(
        XeroConnection $connection,
        string $accessToken,
        array $headers,
        ?string $accept = null,
    ): PendingRequest {
        return $this->http
            ->baseUrl(rtrim($this->config->endpoint('api'), '/').'/')
            ->withToken($accessToken)
            // Caller headers FIRST...
            ->withHeaders($headers)
            // ...then ours via replaceHeaders(), NOT withHeaders().
            //
            // withHeaders() merges recursively, so a caller passing
            // Accept: application/xml would end up sending
            // "application/json, application/xml" rather than being
            // overridden -- and Xero would answer with XML, making every
            // ->json() call silently return null. replaceHeaders() overwrites.
            // (This is the same trap that makes a naive pdf() return JSON.)
            ->replaceHeaders([
                'Xero-tenant-id' => (string) $connection->tenant_id,
                'Accept' => $accept ?? 'application/json',
                'User-Agent' => $this->config->userAgent(),
            ])
            ->timeout((int) $this->config->get('http.timeout', 30))
            ->connectTimeout((int) $this->config->get('http.connect_timeout', 10));
    }

    /**
     * Build the query string ourselves so `where` clauses are encoded EXACTLY
     * once. Xero's filter parser breaks on a double-encoded value: `==`
     * becoming `%253D%253D` puts a literal `%` into the parser.
     */
    private function buildQuery(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            // PHP renders booleans as 1/0, but Xero expects the strings
            // "true"/"false" and silently ignores the numeric form.
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isInsufficientScope(Response $response): bool
    {
        $header = strtolower((string) $response->header('WWW-Authenticate'));

        // Xero's documented spelling is the typo; match both.
        return str_contains($header, 'insufficent_scope')
            || str_contains($header, 'insufficient_scope');
    }

    private function recordRateLimit(Response $response): void
    {
        $this->lastRateLimit = RateLimitStatus::fromResponse($response);

        if ($this->lastRateLimit->isRunningLow()) {
            $this->logger->notice(
                'xero-bridge: approaching a Xero rate limit.',
                ['connection' => $this->connectionKey] + $this->lastRateLimit->toArray(),
            );
        }
    }

    private function toException(Response $response): XeroBridgeException
    {
        $exception = XeroBridgeException::fromResponse($response, $this->connectionKey);

        // Make the wait actionable for a queued job in the two cases where
        // the package refused to sleep inline.
        if ($exception instanceof XeroRateLimitException
            || $exception instanceof XeroServiceUnavailableException
        ) {
            if ($exception->retryAfter() === null) {
                $exception->withRetryAfter(
                    (int) $this->config->get('http.offline_retry_after', 300)
                );
            }
        }

        return $exception;
    }

    /** Only used by the manager when cloning for a different connection. */
    public function connectionKey(): string
    {
        return $this->connectionKey;
    }

    public function generateIdempotencyKey(): string
    {
        return IdempotencyKey::generate();
    }

    public function urlFor(string $uri): string
    {
        return rtrim($this->config->endpoint('api'), '/').'/'.ltrim($uri, '/');
    }

    public function isStringableId(string $value): bool
    {
        return ! Str::contains($value, '/');
    }
}
