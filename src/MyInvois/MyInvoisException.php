<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Everything this module throws.
 *
 * ONE class, not a hierarchy. Callers face a two-branch decision -- "is this
 * worth retrying?" and "is this my configuration?" -- and two predicates answer
 * it without six subclasses to import and keep straight.
 *
 * It is deliberately NOT a subclass of XeroBridgeException, for two reasons:
 *
 *   - An application that wraps its Xero work in catch (XeroBridgeException)
 *     would otherwise silently swallow an LHDN fault and carry on as though
 *     the tax authority had agreed with it.
 *   - XeroBridgeException carries Xero-shaped accessors (connectionKey,
 *     xeroType, xeroErrorNumber, grantedScopes) that can never be populated
 *     here. Inheriting them would mean shipping accessors that always answer
 *     null, which is worse than not having them.
 *
 * NOTHING on this object may contain the client secret or a bearer token. The
 * message, the context and every accessor are built from LHDN's own error
 * envelope, never from the request. There is a test.
 */
class MyInvoisException extends RuntimeException
{
    private ?int $statusCode = null;

    private ?string $correlationId = null;

    private ?int $retryAfter = null;

    private ?string $errorCode = null;

    private ?string $errorMalay = null;

    private ?string $propertyName = null;

    private ?string $propertyPath = null;

    private ?string $target = null;

    /** @var list<string> */
    private array $innerErrors = [];

    private bool $configurationProblem = false;

    /*
    |--------------------------------------------------------------------------
    | Configuration -- nothing left the process
    |--------------------------------------------------------------------------
    */

    public static function disabled(): self
    {
        return (new self(
            'MyInvois is disabled. Set MYINVOIS_ENABLED=true to switch it on, then run '
            .'`php artisan config:clear`. It is off by default because it is only useful in Malaysia.'
        ))->asConfigurationProblem();
    }

    public static function missing(string $key, string $envKey): self
    {
        return (new self(
            "MyInvois is missing [{$key}]. Set {$envKey} in your environment file, then run "
            .'`php artisan config:clear`.'
        ))->asConfigurationProblem();
    }

    /**
     * LHDN auto-blocks a Client ID that sends placeholder values to the token
     * endpoint -- their guidance names "0" and "YOUR_CLIENT_ID" -- so this is
     * caught locally rather than spent as a request.
     */
    public static function placeholder(string $key, string $envKey): self
    {
        return (new self(
            "MyInvois [{$key}] is still set to a placeholder. LHDN blocks a Client ID that sends "
            .'placeholder values to the token endpoint, so nothing was sent. Put the real value in '
            ."{$envKey}."
        ))->asConfigurationProblem();
    }

    public static function unknownEnvironment(string $given): self
    {
        return (new self(sprintf(
            'MYINVOIS_ENVIRONMENT is [%s]; it must be "sandbox" or "production".',
            $given === '' ? '(empty)' : $given,
        )))->asConfigurationProblem();
    }

    public static function unknownIdType(string $given): self
    {
        return (new self(sprintf(
            'Unknown MyInvois idType [%s]. LHDN accepts only: %s.',
            $given === '' ? '(empty)' : $given,
            implode(', ', IdType::values()),
        )))->asConfigurationProblem();
    }

    /*
    |--------------------------------------------------------------------------
    | Token endpoint -- RFC 6749 shaped, not the MyInvois envelope
    |--------------------------------------------------------------------------
    */

    /**
     * The token endpoint answers with { error, error_description, error_uri },
     * which is a different shape from every other endpoint.
     *
     * `unauthorised_client` is matched in BOTH spellings on purpose: LHDN's
     * documentation uses the British one, RFC 6749 specifies the American one,
     * and two characters should not decide whether a real outage is classified
     * as retryable.
     */
    public static function tokenRejected(Response $response): self
    {
        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        $code = isset($body['error']) && is_scalar($body['error']) ? (string) $body['error'] : null;
        $detail = isset($body['error_description']) && is_scalar($body['error_description'])
            ? (string) $body['error_description']
            : null;

        $fatal = in_array($code, [
            'invalid_client',
            'invalid_grant',
            'invalid_scope',
            'unsupported_grant_type',
            'unauthorised_client',
            'unauthorized_client',
        ], true);

        $exception = new self(sprintf(
            'MyInvois refused the credentials (%s)%s. Check MYINVOIS_CLIENT_ID and '
            .'MYINVOIS_CLIENT_SECRET, and that they belong to the [%s] environment.',
            $code ?? 'HTTP '.$response->status(),
            $detail !== null ? ': '.$detail : '',
            (string) config('myinvois.environment', 'sandbox'),
        ));

        $exception->statusCode = $response->status();
        $exception->errorCode = $code;
        $exception->correlationId = $response->header('correlationId') ?: null;

        if ($fatal) {
            $exception->asConfigurationProblem();
        }

        return $exception;
    }

    /*
    |--------------------------------------------------------------------------
    | API endpoints -- the shared LHDN error envelope
    |--------------------------------------------------------------------------
    */

    /**
     * Note what is NOT here: 404. A 404 from the validate endpoint means "that
     * TIN and ID combination cannot be found", which is the answer the caller
     * asked for, so it is returned as false rather than thrown.
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();

        /** @var array<string, mixed> $envelope */
        $envelope = (array) ($response->json('error') ?? []);

        $message = self::string($envelope, 'error')
            ?? self::defaultMessageFor($status);

        $exception = new self(sprintf('MyInvois returned HTTP %d: %s', $status, $message));

        $exception->statusCode = $status;
        $exception->correlationId = $response->header('correlationId') ?: null;
        $exception->errorCode = self::string($envelope, 'errorCode');
        $exception->errorMalay = self::string($envelope, 'errorMS');
        $exception->propertyName = self::string($envelope, 'propertyName');
        $exception->propertyPath = self::string($envelope, 'propertyPath');
        $exception->target = self::string($envelope, 'target');

        // header() answers '' rather than null when the header is absent.
        $retryAfter = trim($response->header('Retry-After'));
        $exception->retryAfter = ctype_digit($retryAfter) ? (int) $retryAfter : null;

        if (isset($envelope['innerError']) && is_array($envelope['innerError'])) {
            foreach ($envelope['innerError'] as $inner) {
                if (is_array($inner)) {
                    $text = self::string($inner, 'error') ?? self::string($inner, 'errorCode');

                    if ($text !== null) {
                        $exception->innerErrors[] = $text;
                    }
                } elseif (is_scalar($inner)) {
                    $exception->innerErrors[] = (string) $inner;
                }
            }
        }

        // 401 after a fresh token, and 403, are both provisioning problems: the
        // credentials are not entitled to this API. Retrying cannot fix either.
        if ($status === 401 || $status === 403) {
            $exception->asConfigurationProblem();
        }

        return $exception;
    }

    private static function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400 => 'the request was malformed (BadArgument). This is a bug in the caller, not a '
                .'problem with the taxpayer.',
            401 => 'the access token was rejected even after a fresh one was obtained.',
            403 => 'these credentials are not entitled to the taxpayer validation API. Check the '
                .'ERP registration in the MyInvois portal.',
            429 => 'the rate limit was exceeded. This endpoint allows 60 requests per minute per '
                .'Client ID.',
            500, 503 => 'LHDN reported a server-side failure. Retry with backoff.',
            501 => 'LHDN reported the operation as not implemented. Do not retry.',
            default => 'no further detail was supplied.',
        };
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key): ?string
    {
        if (! isset($source[$key]) || ! is_scalar($source[$key])) {
            return null;
        }

        $value = trim((string) $source[$key]);

        return $value === '' ? null : $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /** LHDN support asks for this. It is on every response, so log it. */
    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /** LHDN returns the same message in Malay. Useful in a Malaysian UI. */
    public function errorMalay(): ?string
    {
        return $this->errorMalay;
    }

    public function propertyName(): ?string
    {
        return $this->propertyName;
    }

    public function propertyPath(): ?string
    {
        return $this->propertyPath;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    /** @return list<string> */
    public function innerErrors(): array
    {
        return $this->innerErrors;
    }

    /**
     * True when the same request might succeed later without anyone changing
     * anything: a rate limit or a server-side failure.
     */
    public function isRetryable(): bool
    {
        return in_array($this->statusCode, [429, 500, 502, 503, 504], true);
    }

    /**
     * True when a human has to change a setting or a portal registration. Never
     * retry these -- with LHDN's placeholder blocking, hammering them can cost
     * you the Client ID.
     */
    public function isConfigurationProblem(): bool
    {
        return $this->configurationProblem;
    }

    /**
     * Everything safe to log. Deliberately excludes anything derived from the
     * request, so a token or the client secret cannot reach a log through here.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'status' => $this->statusCode,
            'correlation_id' => $this->correlationId,
            'error_code' => $this->errorCode,
            'property_name' => $this->propertyName,
            'property_path' => $this->propertyPath,
            'target' => $this->target,
            'retry_after' => $this->retryAfter,
            'inner_errors' => $this->innerErrors ?: null,
        ], static fn ($value) => $value !== null);
    }

    private function asConfigurationProblem(): self
    {
        $this->configurationProblem = true;

        return $this;
    }
}
