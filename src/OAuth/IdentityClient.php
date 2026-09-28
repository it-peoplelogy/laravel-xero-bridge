<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Exceptions\XeroIdentityUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Throwable;

/**
 * Talks to identity.xero.com: code exchange, refresh and revocation.
 *
 * Its real job is CLASSIFICATION. Every failure is sorted into exactly one of
 * three buckets, because the caller must react differently to each:
 *
 *   transient  -> XeroIdentityUnavailableException      (retry; change nothing)
 *   terminal   -> XeroReauthorizationRequiredException  (a human must reconnect)
 *   app config -> XeroConfigurationException            (fix .env)
 *
 * Getting this wrong in the obvious direction -- treating any failure as
 * terminal -- is the live bug in the host project, where one transient 502
 * wipes the refresh token permanently.
 */
final class IdentityClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly XeroConfig $config,
    ) {}

    /**
     * Exchange an authorisation code. Note the code is single-use and expires
     * after 5 minutes, so this retries ONLY on a connection-level error --
     * never on a returned 4xx/5xx, because if Xero answered at all it may
     * already have consumed the code, and retrying yields a misleading
     * invalid_grant.
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): TokenResponse
    {
        $response = $this->post([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], retryServerErrors: false);

        return $this->toTokenResponse($response, connectionKey: null);
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * Xero ROTATES refresh tokens: a successful call invalidates the token
     * passed in. If the caller then fails to persist the new one, the old one
     * still works for a 30-minute grace window -- which is why a save failure
     * must never invalidate the connection.
     */
    public function refresh(string $refreshToken, string $connectionKey): TokenResponse
    {
        // Unlike the code exchange, a 5xx here IS retried in place. Xero keeps
        // the previous refresh token usable for a 30-minute grace window, so a
        // retry after a server error is safe even if Xero actually processed
        // the first attempt and rotated.
        $response = $this->post([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], retryServerErrors: true, connectionKey: $connectionKey);

        return $this->toTokenResponse($response, $connectionKey);
    }

    /**
     * Revoke a refresh token.
     *
     * This disconnects EVERY tenant authorised under the same auth event, not
     * just one organisation. To disconnect a single organisation use
     * DELETE https://api.xero.com/connections/{connection_id} instead.
     */
    public function revoke(string $refreshToken): void
    {
        $this->request()
            ->asForm()
            ->post($this->config->endpoint('revocation'), ['token' => $refreshToken]);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function post(array $payload, bool $retryServerErrors, ?string $connectionKey = null): Response
    {
        $request = $this->request()->retry(
            max(1, (int) $this->config->get('tokens.http_retries', 2)),
            250,
            static function (Throwable $e) use ($retryServerErrors) {
                // A connection-level failure is always safe to retry: the
                // request may never have reached Xero at all.
                if ($e instanceof ConnectionException) {
                    return true;
                }

                // A returned status is only retried for the refresh grant.
                // An authorisation code is single-use, so if Xero answered at
                // all it may already have consumed it, and retrying produces a
                // misleading invalid_grant.
                if (! $retryServerErrors || ! $e instanceof RequestException) {
                    return false;
                }

                return $e->response->serverError();
            },
            throw: false,
        );

        try {
            return $request->asForm()->post($this->config->endpoint('token'), $payload);
        } catch (ConnectionException $e) {
            throw XeroIdentityUnavailableException::make(
                $connectionKey ?? '-',
                $e->getMessage(),
            );
        }
    }

    private function request()
    {
        return $this->http
            // Xero expects HTTP Basic auth on the token and revocation
            // endpoints, not client_secret in the form body.
            ->withBasicAuth($this->config->clientId(), $this->config->clientSecret())
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout((int) $this->config->get('tokens.http_timeout', 8));
    }

    private function toTokenResponse(Response $response, ?string $connectionKey): TokenResponse
    {
        if ($response->successful()) {
            return TokenResponse::fromArray((array) $response->json());
        }

        throw $this->classify($response, $connectionKey);
    }

    /**
     * The identity host speaks snake_case OAuth errors
     * ({"error":"invalid_grant","error_description":"..."}), which is a
     * different shape again from the Accounting API's error envelopes.
     */
    private function classify(Response $response, ?string $connectionKey): XeroBridgeException
    {
        $status = $response->status();
        $body = (array) ($response->json() ?? []);
        $error = isset($body['error']) ? (string) $body['error'] : null;
        $description = isset($body['error_description']) ? (string) $body['error_description'] : null;

        // Transient: anything at or above 500, plus rate limiting. The tokens
        // are untouched and the caller should simply try again.
        if ($status >= 500 || $status === 429) {
            return XeroIdentityUnavailableException::make(
                $connectionKey ?? '-',
                "Xero returned HTTP {$status}",
                $status,
            );
        }

        // The application's own credentials are wrong. Marking every
        // connection expired for this would be badly wrong: the fix is one
        // line of .env, not a re-consent by every user.
        if (in_array($error, ['invalid_client', 'unauthorized_client'], true) || $status === 401) {
            return XeroConfigurationException::credentialsRejected()
                ->withResponse($response, $connectionKey);
        }

        // The one genuinely terminal case for a connection.
        if ($error === 'invalid_grant') {
            $key = $connectionKey ?? $this->config->defaultConnection();

            return XeroReauthorizationRequiredException::for(
                $key,
                $this->config->connectUrl($key),
                $description ?? 'invalid_grant',
            )->withResponse($response, $connectionKey);
        }

        return (new XeroBridgeException(
            'Xero rejected the token request'
            .($error !== null ? " [{$error}]" : '')
            .': '.($description ?? "HTTP {$status}")
        ))->withResponse($response, $connectionKey);
    }
}
