<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Capture;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Peoplelogy\XeroBridge\Models\XeroApiCall;
use Peoplelogy\XeroBridge\Support\Clock;
use Peoplelogy\XeroBridge\Support\RedactionPolicy;
use Peoplelogy\XeroBridge\Support\Redactor;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one row per wire attempt into xero_api_calls.
 *
 * Reads ConfigRepository directly rather than Support\XeroConfig, so the
 * MyInvois module can take this in its constructor without breaching the arch
 * rule that keeps LHDN and Xero apart -- the same reason MyInvoisAudit takes
 * TableGuard and Clock and nothing else.
 *
 * THREE PROPERTIES, each the answer to a way this could go wrong:
 *
 *   IT NEVER THROWS. Recording is a side benefit; it must never take down the
 *   call it was recording. Every path out of here is wrapped, and a failure
 *   warns once rather than per call. This is the same contract TableGuard and
 *   the other recorders already hold.
 *
 *   IT REDACTS BEFORE THE INSERT. Nothing raw is handed to the database layer,
 *   because a raw row reaches the binary log, the nightly backup, the read
 *   replica and every SELECT * in a support tool. A bug in a write-side
 *   redactor loses a field; a bug in a read-side one is a leak that has already
 *   happened.
 *
 *   IT RECORDS EVERY ATTEMPT. A single logical call can be two HTTP requests
 *   when the client replays a 401 with a refreshed token. Both are rows, joined
 *   by logical_call_id, because "why did this take two round trips?" is a
 *   question the table exists to answer.
 */
final class ApiCallRecorder
{
    /**
     * Response bodies are stored only when they are JSON.
     *
     * A PDF is megabytes of binary whose rendered content carries the
     * organisation's own bank details out of the branding theme, and no key
     * rule can reach inside it. The test is therefore positive -- store JSON --
     * rather than a list of binary endpoints, because XeroBridge::raw() means
     * the endpoint set is open and no such list could ever be complete.
     */
    private const JSON_TYPES = ['application/json', 'text/json', '+json'];

    private bool $warned = false;

    private ?Model $owner = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RedactionPolicy $policy,
        private readonly TableGuard $tables,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Attribute the calls made inside $work to one of the host's own records.
     *
     * This is what makes a dashboard possible: without an owner a project
     * cannot join these rows to its orders or its customers. Scoped to the
     * closure and restored afterwards, so an owner can never leak into the
     * next call on a long-lived worker.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function forOwner(?Model $owner, callable $work): mixed
    {
        $previous = $this->owner;
        $this->owner = $owner;

        try {
            return $work();
        } finally {
            $this->owner = $previous;
        }
    }

    /** A cheap gate, so a call site does no work when capture is off. */
    public function enabled(): bool
    {
        return (bool) $this->config->get('xero-bridge.capture.enabled', false);
    }

    /** One id shared by every wire attempt of one logical call. */
    public function newCallId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param  array<string, mixed>  $requestHeaders
     * @param  array<string, mixed>  $requestQuery
     * @param  float  $startedAt  an hrtime(true) reading from before the call
     */
    public function record(
        string $channel,
        string $method,
        string $url,
        array $requestHeaders = [],
        array $requestQuery = [],
        mixed $requestBody = null,
        ?Response $response = null,
        ?Throwable $error = null,
        ?float $startedAt = null,
        ?string $connectionKey = null,
        ?string $tenantId = null,
        ?string $logicalCallId = null,
        int $attempt = 1,
    ): void {
        try {
            if (! $this->usable($channel)) {
                return;
            }

            $status = $response?->status();

            if (! $this->wanted($method, $status, $error)) {
                return;
            }

            if ($this->policy->skips($url)) {
                return;
            }

            $redactor = new Redactor($this->policy, $channel);

            // Order matters. The request is walked FIRST so its removed values
            // are remembered, which is what lets the response be scrubbed of
            // the same values quoted back inside a validation message.
            $safeUrl = $redactor->urlWithQuery($url, $requestQuery);
            $safeRequestHeaders = $redactor->headers($requestHeaders);
            $safeRequestBody = $redactor->body($requestBody);

            $safeResponseHeaders = $response !== null
                ? $redactor->headers($this->flatten($response->headers()))
                : [];

            [$contentType, $bytes, $safeResponseBody] = $this->responseBody($response, $redactor);

            XeroApiCall::create([
                'channel' => $channel,
                'connection_key' => $connectionKey,
                'tenant_id' => $tenantId ?? $this->headerValue($requestHeaders, 'xero-tenant-id'),
                'logical_call_id' => $logicalCallId ?? $this->newCallId(),
                'attempt' => $attempt,
                'method' => strtoupper($method),
                'url' => $safeUrl,
                'request_headers' => $safeRequestHeaders ?: null,
                'request_body' => $this->cap($safeRequestBody),
                'status' => $status,
                'response_headers' => $safeResponseHeaders ?: null,
                'response_body' => $this->cap($safeResponseBody),
                'content_type' => $contentType,
                'response_bytes' => $bytes,
                'duration_ms' => $startedAt === null
                    ? null
                    : (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'error' => $error === null
                    ? null
                    : mb_substr($redactor->scrubText($error::class.': '.$error->getMessage()), 0, 500),
                'redacted_keys' => $redactor->hits() ?: null,
                'idempotency_key' => $this->headerValue($requestHeaders, 'idempotency-key'),
                'correlation_id' => $response === null
                    ? null
                    : ($response->header('correlationId') ?: null),
                'owner_type' => $this->owner?->getMorphClass(),
                'owner_id' => $this->owner === null ? null : (string) $this->owner->getKey(),
                'created_at' => Clock::now(),
            ]);
        } catch (Throwable $e) {
            $this->warnOnce($e);
        }
    }

    /**
     * The response body, but only when it is JSON.
     *
     * @return array{0: string|null, 1: int|null, 2: array<mixed>|null}
     */
    private function responseBody(?Response $response, Redactor $redactor): array
    {
        if ($response === null) {
            return [null, null, null];
        }

        $contentType = $response->header('Content-Type') ?: null;

        try {
            $raw = $response->body();
        } catch (Throwable) {
            return [$contentType, null, null];
        }

        $bytes = strlen($raw);

        if (! $this->isJson($contentType)) {
            // Keep the fact and the size, drop the bytes. bodyWasDropped() on
            // the model reads exactly this pair back.
            return [$contentType, $bytes, null];
        }

        return [$contentType, $bytes, $redactor->body($response->json())];
    }

    private function isJson(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $lower = strtolower($contentType);

        foreach (self::JSON_TYPES as $type) {
            if (str_contains($lower, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace an oversized body with a marker naming its shape.
     *
     * The cap is applied AFTER decoding and redacting, never as a substr() on
     * the raw bytes. Truncating first would leave a document nothing can parse,
     * the walk would silently no-op, and the surviving prefix is exactly where
     * the sensitive fields sit.
     *
     * @param  array<mixed>|null  $body
     * @return array<mixed>|null
     */
    private function cap(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $max = max(1024, (int) $this->config->get('xero-bridge.capture.max_body_bytes', 65536));

        $json = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false) {
            // Xero returns Windows-1252 bytes in names copied out of a
            // spreadsheet more often than anyone expects, and such a string
            // would otherwise be rejected by a utf8mb4 column.
            return ['_unencodable' => true, '_keys' => array_keys($body)];
        }

        if (strlen($json) <= $max) {
            return $body;
        }

        return [
            '_truncated' => true,
            '_bytes' => strlen($json),
            '_keys' => array_keys($body),
        ];
    }

    /**
     * Response::headers() gives every header as a list, because a header may
     * legally repeat. One string per name is what a reader wants.
     *
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function flatten(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $values) {
            $out[$name] = implode(', ', $values);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $headers */
    private function headerValue(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower(trim((string) $name)) === $wanted && is_scalar($value)) {
                return mb_substr((string) $value, 0, 128);
            }
        }

        return null;
    }

    /**
     * Which calls earn a row.
     *
     * A failure is ALWAYS captured, whatever the mode and whatever the method.
     * `writes` exists to keep the flood of GET /Invoices during a sync out of
     * the table, not to hide the read that failed -- and a Xero error can
     * arrive as a 200, so a rule keyed only on the status would let a rejected
     * batch through as ordinary read traffic.
     */
    private function wanted(string $method, ?int $status, ?Throwable $error): bool
    {
        $failed = $error !== null || $status === null || $status < 200 || $status >= 300;

        return match ((string) $this->config->get('xero-bridge.capture.mode', 'writes')) {
            'all' => true,
            'errors' => $failed,
            default => $failed || ! in_array(strtoupper($method), ['GET', 'HEAD'], true),
        };
    }

    private function usable(string $channel): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->config->get('xero-bridge.capture.channels.'.$channel, true) === false) {
            return false;
        }

        return $this->tables->has(
            $this->config->get('xero-bridge.database.connection'),
            (string) $this->config->get('xero-bridge.capture.table', 'xero_api_calls'),
            'xero-bridge-migrations',
        );
    }

    private function warnOnce(Throwable $e): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        $this->logger->warning(
            'xero-bridge: could not record an API call, so the capture table will have gaps. '
            .'The call itself was unaffected.',
            ['exception' => $e->getMessage()],
        );
    }
}
