<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * The application is misconfigured. Nothing about the Xero connection is
 * wrong, so no connection is ever invalidated for this -- the fix is in .env
 * or config/xero-bridge.php.
 */
class XeroConfigurationException extends XeroBridgeException
{
    public static function missing(string $configKey, string $envKey): self
    {
        return new self(
            "Xero Bridge is missing [{$configKey}]. Set {$envKey} in your .env file. "
            .'Create the application at https://developer.xero.com/myapps to obtain it.'
        );
    }

    public static function redirectUriNotSet(): self
    {
        return new self(
            'Xero Bridge could not determine a redirect URI. Set XERO_REDIRECT_URI to a URI '
            .'registered on your Xero app, or enable the package routes so the callback route exists.'
        );
    }

    public static function loopbackRedirectUri(string $uri): self
    {
        // Xero's own error for this is uselessly vague, so name the fix.
        return new self(
            "Xero rejects [{$uri}] as a redirect URI: http://127.0.0.1 is explicitly not allowed. "
            .'Use http://localhost instead (allowed for testing), or an https URI.'
        );
    }

    public static function insecureRedirectUri(string $uri): self
    {
        return new self(
            "Xero requires an https redirect URI; [{$uri}] is not. The only exception is "
            .'http://localhost for local testing.'
        );
    }

    public static function offlineAccessMissing(): self
    {
        return new self(
            'XERO_SCOPES does not include "offline_access". Without it Xero issues no refresh '
            .'token, so the connection would stop working 30 minutes after you create it. '
            .'Add offline_access to XERO_SCOPES and connect again.'
        );
    }

    public static function credentialsRejected(): self
    {
        return new self(
            'Xero rejected the application credentials. Check XERO_CLIENT_ID and '
            .'XERO_CLIENT_SECRET against https://developer.xero.com/myapps. This is an '
            .'application configuration problem, not an expired connection.'
        );
    }

    /**
     * APP_KEY rotation makes every stored token undecryptable. Without this,
     * the caller sees a bare DecryptException from deep inside Eloquent with
     * nothing linking it to Xero.
     */
    public static function unreadableTokens(string $key, string $connectUrl): self
    {
        return (new self(
            "The stored Xero tokens for connection [{$key}] cannot be decrypted. This normally "
            ."means APP_KEY changed since they were saved. Re-authorise at {$connectUrl}."
        ))->withConnectionKey($key);
    }

    public static function invalidConflictPolicy(string $setting, string $value): self
    {
        return new self("Invalid value [{$value}] for xero-bridge.{$setting}.");
    }
}
