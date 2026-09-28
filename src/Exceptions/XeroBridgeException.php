<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Base for everything this package throws.
 *
 * Subclasses are discriminated by WHAT THE CALLER MUST DO DIFFERENTLY --
 * re-authorise, re-consent, release the job, fix the payload, fix .env -- not
 * by which Xero endpoint failed. A single exception class would force callers
 * into str_contains($e->getMessage(), ...), which is exactly the fragility
 * this package exists to replace. Every subclass extends this one, so
 * catch (XeroBridgeException) remains a valid coarse catch and every accessor
 * is available regardless of which subclass arrived.
 *
 * No exception message or context() value may ever contain an access token, a
 * refresh token or the client secret. There is a test for that.
 */
class XeroBridgeException extends RuntimeException
{
    protected ?Response $response = null;

    protected ?int $statusCode = null;

    protected ?string $connectionKey = null;

    protected ?string $xeroType = null;

    protected ?int $errorNumber = null;

    protected ?int $retryAfter = null;

    /** @var list<string> */
    protected array $validationErrors = [];

    /** @var array<int, list<string>> */
    protected array $errorsByElement = [];

    public function response(): ?Response
    {
        return $this->response;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function connectionKey(): ?string
    {
        return $this->connectionKey;
    }

    /**
     * The messages from Elements[].ValidationErrors[], flattened.
     *
     * @return list<string>
     */
    public function validationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * The same messages, keyed by their index in Elements[] -- so a bulk
     * invoice POST can report "row 3 failed because...".
     *
     * @return array<int, list<string>>
     */
    public function validationErrorsByElement(): array
    {
        return $this->errorsByElement;
    }

    public function xeroType(): ?string
    {
        return $this->xeroType;
    }

    public function xeroErrorNumber(): ?int
    {
        return $this->errorNumber;
    }

    /** Seconds to wait before retrying, where Xero told us. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Safe to hand straight to Log::error(). Deliberately excludes the request
     * headers and body, which carry the bearer token.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'connection' => $this->connectionKey,
            'status' => $this->statusCode,
            'xero_type' => $this->xeroType,
            'xero_error_number' => $this->errorNumber,
            'retry_after' => $this->retryAfter,
            'validation_errors' => $this->validationErrors,
        ], static fn ($value) => $value !== null && $value !== []);
    }

    public function withResponse(?Response $response, ?string $connectionKey = null): static
    {
        $this->response = $response;
        $this->statusCode = $response?->status();
        $this->connectionKey = $connectionKey ?? $this->connectionKey;

        return $this;
    }

    public function withConnectionKey(?string $connectionKey): static
    {
        $this->connectionKey = $connectionKey;

        return $this;
    }

    public function withStatusCode(?int $status): static
    {
        $this->statusCode = $status;

        return $this;
    }

    public function withRetryAfter(?int $seconds): static
    {
        $this->retryAfter = $seconds;

        return $this;
    }

    public function withXeroType(?string $type, int|string|null $errorNumber = null): static
    {
        $this->xeroType = $type;
        $this->errorNumber = $errorNumber === null ? null : (int) $errorNumber;

        return $this;
    }

    /**
     * @param  list<string>  $errors
     * @param  array<int, list<string>>  $byElement
     */
    public function withValidationErrors(array $errors, array $byElement = []): static
    {
        $this->validationErrors = $errors;
        $this->errorsByElement = $byElement;

        return $this;
    }

    /**
     * Map a failed Xero response onto the right exception.
     *
     * Xero speaks FOUR different error dialects and the package has to read
     * all of them:
     *
     *   a) 400 validation -- {"ErrorNumber":10,"Type":"ValidationException",
     *      "Message":"...","Elements":[{"ValidationErrors":[{"Message":"..."}]}]}
     *   b) 401/403 -- a PascalCase problem envelope,
     *      {"Type":null,"Title":"Unauthorized","Status":401,"Detail":"...","Instance":"..."}
     *   c) 503 -- PLAIN TEXT, not JSON ("The Organisation is offline")
     *   d) identity.xero.com -- snake_case OAuth, {"error":"invalid_grant"}
     *
     * Building the message here rather than relying on ->throw() also sidesteps
     * Laravel's 120-character truncation of RequestException messages, which
     * would otherwise hide the ValidationErrors that are the whole point.
     */
    public static function fromResponse(Response $response, ?string $connectionKey = null): self
    {
        $status = $response->status();
        $json = $response->json();
        $retryAfter = self::retryAfterSeconds($response);

        // Checked before anything else, because Xero often sends a 429 with
        // an empty body and the useful detail is entirely in the headers.
        if ($status === 429) {
            $problem = $response->header('X-Rate-Limit-Problem') ?: null;

            return (new XeroRateLimitException(
                'Xero rate limit exceeded'
                .($problem !== null ? " ({$problem})" : '')
                .($retryAfter !== null ? ", retry after {$retryAfter}s." : '.')
            ))
                ->withLimitProblem($problem)
                ->withRetryAfter($retryAfter)
                ->withResponse($response, $connectionKey);
        }

        // (c) Not JSON at all: plain-text outages, an empty 500, or XML when
        // the Accept header went missing.
        if (! is_array($json)) {
            return self::fromNonJson($response, $status, $retryAfter, $connectionKey);
        }

        // (d) OAuth-style errors from the identity host.
        if (isset($json['error']) && is_string($json['error'])) {
            $description = isset($json['error_description'])
                ? (string) $json['error_description']
                : (string) $json['error'];

            return (new XeroRequestException("Xero OAuth error [{$json['error']}]: {$description}"))
                ->withResponse($response, $connectionKey);
        }

        // (b) The PascalCase problem envelope used for 401/403.
        if (array_key_exists('Detail', $json) || array_key_exists('Title', $json)) {
            $detail = (string) ($json['Detail'] ?? $json['Title'] ?? 'Unknown authentication error');

            if (in_array($status, [401, 403], true)) {
                return (new XeroAuthenticationException("Xero authentication failed [{$status}]: {$detail}"))
                    ->withResponse($response, $connectionKey);
            }

            return (new XeroRequestException("Xero request failed [{$status}]: {$detail}"))
                ->withResponse($response, $connectionKey);
        }

        if (in_array($status, [500, 502, 503, 504], true)) {
            return (new XeroServiceUnavailableException("Xero is unavailable (HTTP {$status})."))
                ->withRetryAfter($retryAfter ?? 300)
                ->withResponse($response, $connectionKey);
        }

        // (a) The validation envelope.
        [$errors, $byElement] = self::extractValidationErrors($json);
        $message = (string) ($json['Message'] ?? "Xero rejected the request (HTTP {$status}).");

        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            $message .= ': '.implode('; ', $shown);

            if (count($errors) > 5) {
                $message .= sprintf(' (+%d more)', count($errors) - 5);
            }

            return (new XeroValidationException($message))
                ->withValidationErrors($errors, $byElement)
                ->withXeroType(
                    isset($json['Type']) ? (string) $json['Type'] : null,
                    $json['ErrorNumber'] ?? null,
                )
                ->withResponse($response, $connectionKey);
        }

        return (new XeroRequestException("Xero request failed [{$status}]: {$message}"))
            ->withResponse($response, $connectionKey);
    }

    private static function fromNonJson(
        Response $response,
        int $status,
        ?int $retryAfter,
        ?string $connectionKey,
    ): self {
        $raw = trim((string) $response->body());

        // Detected on the RAW body: strip_tags() would erase the markup that
        // identifies it.
        if (str_starts_with($raw, '<?xml') || str_contains($raw, '<Response')) {
            return (new XeroRequestException(
                'Xero returned XML instead of JSON, which means the "Accept: application/json" '
                .'header was missing from the request.'
            ))->withResponse($response, $connectionKey);
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($raw)));

        if (in_array($status, [500, 502, 503, 504], true)) {
            return (new XeroServiceUnavailableException(
                $text !== ''
                    ? "Xero is unavailable (HTTP {$status}): {$text}"
                    : "Xero is unavailable (HTTP {$status})."
            ))
                // Xero suggests ~5 minutes for "The Organisation is offline".
                ->withRetryAfter($retryAfter ?? 300)
                ->withResponse($response, $connectionKey);
        }

        if ($status === 412) {
            return (new XeroConfigurationException(
                'Xero returned 412 Precondition Failed, which usually means an outdated TLS '
                .'version (TLS 1.2 or newer is required). Body: '.mb_substr($text, 0, 300)
            ))->withResponse($response, $connectionKey);
        }

        return (new XeroRequestException(
            "Xero request failed [{$status}]: ".mb_substr($text, 0, 500)
        ))->withResponse($response, $connectionKey);
    }

    /**
     * Walk every place Xero puts validation messages.
     *
     * Note both `Message` AND `Description` are read: Xero's own docs use
     * Message in one bulk-response example and Description in another, for
     * the same feature.
     *
     * @param  array<mixed>  $json
     * @return array{0: list<string>, 1: array<int, list<string>>}
     */
    private static function extractValidationErrors(array $json): array
    {
        $flat = [];
        $byElement = [];

        $read = static function (mixed $node): array {
            if (! is_array($node) || ! isset($node['ValidationErrors']) || ! is_array($node['ValidationErrors'])) {
                return [];
            }

            $out = [];

            foreach ($node['ValidationErrors'] as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $message = $error['Message'] ?? $error['Description'] ?? null;

                if (is_string($message) && $message !== '') {
                    $out[] = $message;
                }
            }

            return $out;
        };

        // 1. The standard 400 shape.
        foreach ((array) ($json['Elements'] ?? []) as $index => $element) {
            $messages = $read($element);

            if ($messages !== []) {
                $byElement[(int) $index] = $messages;
                $flat = array_merge($flat, $messages);
            }
        }

        // 2. Occasionally at the top level.
        $flat = array_merge($flat, $read($json));

        // 3. Inside the resource collection, which is where a bulk
        //    summarizeErrors=false response puts them.
        foreach ($json as $key => $value) {
            if ($key === 'Elements' || ! is_array($value) || ! array_is_list($value)) {
                continue;
            }

            foreach ($value as $index => $item) {
                $messages = $read($item);

                if ($messages !== []) {
                    $byElement[(int) $index] = array_merge($byElement[(int) $index] ?? [], $messages);
                    $flat = array_merge($flat, $messages);
                }
            }
        }

        return [array_values(array_unique($flat)), $byElement];
    }

    /** Handles both the numeric and the HTTP-date forms of Retry-After. */
    public static function retryAfterSeconds(Response $response): ?int
    {
        // Response::header() returns '' rather than null for an absent header.
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit(trim($header))) {
            return (int) trim($header);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
