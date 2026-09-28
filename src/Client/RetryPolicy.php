<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Throwable;

/**
 * Decides whether and how long to wait before retrying.
 *
 * Laravel's HTTP client has NO built-in Retry-After support, so honouring it
 * is entirely this class's job, expressed through retry()'s callable delay
 * (which must return MILLISECONDS).
 *
 * Two Xero-specific facts drive the design:
 *
 *  - Retry-After is sent only for the MINUTE and DAILY limits. A 429 caused
 *    by the 5-concurrent-calls limit carries no Retry-After at all, so a
 *    sane short default is required or the backoff falls back to something
 *    far too long for a limit that frees in milliseconds.
 *  - A daily-limit Retry-After can be hours. Sleeping that long inline would
 *    pin a worker, so anything above the ceiling is refused and surfaces as
 *    an exception carrying retryAfter(), letting a queued job release itself.
 */
final class RetryPolicy
{
    public function __construct(private readonly XeroConfig $config) {}

    public function times(): int
    {
        return max(1, (int) $this->config->get('http.retries', 3));
    }

    private function maxMs(): int
    {
        return (int) $this->config->get('http.retry_max_ms', 30000);
    }

    /** @return list<int> */
    private function retryStatuses(): array
    {
        return (array) $this->config->get('http.retry_status', [429, 500, 502, 503, 504]);
    }

    public function delayMs(int $attempt, Throwable $e): int
    {
        $response = $e instanceof RequestException ? $e->response : null;

        if ($response !== null) {
            $after = XeroBridgeException::retryAfterSeconds($response);

            if ($after !== null) {
                return min($after * 1000, $this->maxMs());
            }

            if ($response->status() === 429) {
                // No Retry-After means the concurrency limit, which frees in
                // milliseconds -- back off briefly, not for a whole minute.
                $base = (int) $this->config->get('http.concurrency_backoff_ms', 250);

                return min(2000, (int) ($base * (2 ** max(0, $attempt - 1))) + random_int(0, 100));
            }
        }

        $base = (int) $this->config->get('http.retry_base_ms', 1000);

        return min($this->maxMs(), (int) ($base * (2 ** max(0, $attempt - 1))) + random_int(0, 250));
    }

    public function shouldRetry(Throwable $e): bool
    {
        // The request may never have reached Xero, so this is always safe.
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! $e instanceof RequestException) {
            return false;
        }

        $response = $e->response;
        $status = $response->status();

        // Never blind-retry an auth failure: a 401 needs a token refresh or
        // re-consent, and retrying an insufficient_scope 401 loops forever.
        if ($status === 401 || $status === 403) {
            return false;
        }

        if (! in_array($status, $this->retryStatuses(), true)) {
            return false;
        }

        // "The Organisation is offline" wants ~5 minutes, which is not an
        // inline wait -- surface it so the caller can reschedule.
        if ($status === 503 && $this->isLongOutage($response)) {
            return false;
        }

        // Refuse to sleep past the ceiling. A daily-limit Retry-After of
        // several hours must become an exception carrying retryAfter() so a
        // queued job can release itself, not a blocked worker.
        //
        // Compared against the UNCLAMPED value on purpose: delayMs() clamps
        // to the ceiling, so comparing that would always pass.
        $requested = XeroBridgeException::retryAfterSeconds($response);

        return $requested === null || ($requested * 1000) <= $this->maxMs();
    }

    /** Only POST/PUT/PATCH are unsafe; a retried write can duplicate an invoice. */
    public function retriesMethod(string $method, bool $hasIdempotencyKey): bool
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return $hasIdempotencyKey;
    }

    public function isLongOutage(Response $response): bool
    {
        $body = strtolower((string) $response->body());

        return str_contains($body, 'organisation is offline')
            || str_contains($body, 'offline for maintenance');
    }
}
