<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;

/**
 * Typed, validating reader for config/xero-bridge.php.
 *
 * Everything that can be wrong in .env is caught here, at the point of use,
 * with a message that names the env key and the fix -- rather than surfacing
 * later as an opaque 401 from Xero.
 */
final class XeroConfig
{
    public function __construct(private readonly Repository $config) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get("xero-bridge.{$key}", $default);
    }

    public function clientId(): string
    {
        $value = (string) ($this->get('client_id') ?? '');

        if ($value === '') {
            throw XeroConfigurationException::missing('client_id', 'XERO_CLIENT_ID');
        }

        return $value;
    }

    public function clientSecret(): string
    {
        $value = (string) ($this->get('client_secret') ?? '');

        if ($value === '') {
            throw XeroConfigurationException::missing('client_secret', 'XERO_CLIENT_SECRET');
        }

        return $value;
    }

    public function webhookKey(): ?string
    {
        $value = $this->get('webhook_key');

        return ($value === null || $value === '') ? null : (string) $value;
    }

    public function endpoint(string $name): string
    {
        return (string) $this->get("endpoints.{$name}");
    }

    public function defaultConnection(): string
    {
        return (string) ($this->get('default_connection') ?: 'default');
    }

    public function scopes(): string
    {
        return Scopes::normalize($this->get('scopes'));
    }

    /**
     * The cache store XERO_LOCK_STORE names, or null for the default store.
     *
     * Only a non-empty string names a store. env() reads a bare
     * XERO_LOCK_STORE= as '' and XERO_LOCK_STORE=false as false, and neither
     * is a decision -- the same rule XERO_CONSOLE_ENABLED= follows. It matters
     * because the cache manager changed underneath: Laravel 11 and 12 before
     * 12.43 treat '' as "the default store", while 12.43+ and 13 look it up by
     * name and throw "Cache store [] is not defined" -- which would fail every
     * token refresh on a host that copied the key out of an example file.
     */
    public function lockStore(): ?string
    {
        $name = $this->get('tokens.lock_store');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function routePrefix(): string
    {
        return trim((string) $this->get('routes.prefix', 'xero'), '/');
    }

    public function routeName(string $suffix): string
    {
        return ((string) $this->get('routes.name_prefix', 'xero-bridge.')).$suffix;
    }

    /**
     * The webhook route's URI: relative to the site root, no slash at either
     * end. The one place the rule lives, so the route file and anything that
     * prints the URL cannot disagree.
     *
     * webhooks.prefix replaces routes.prefix for this route alone. Null or ''
     * follows routes.prefix, which is what every config published before the
     * key existed gets, so their URL never moves; '/' is the explicit way to
     * say "the site root".
     *
     * routes.prefix is read with NO 'xero' default, and webhooks.path with a
     * default that applies only when the key is missing -- both exactly as
     * routes/webhook.php read them before this method existed. A leading slash
     * on the path is not "absolute": it has always been trimmed, so
     * XERO_WEBHOOK_PATH=/webhook means /xero/webhook and has to keep meaning it.
     */
    public function webhookUri(): string
    {
        $prefix = $this->get('webhooks.prefix');

        if ($prefix === null || $prefix === '') {
            $prefix = $this->get('routes.prefix');
        }

        return trim(
            trim((string) $prefix, '/').'/'.trim((string) $this->get('webhooks.path', 'webhook'), '/'),
            '/',
        );
    }

    /**
     * The connect URL for a key, used in almost every error message. Falls
     * back to a plain path when the routes are disabled, so the guidance is
     * still actionable.
     */
    public function connectUrl(string $key): string
    {
        $name = $this->routeName('connect');

        if (Route::has($name)) {
            return route($name, ['key' => $key]);
        }

        return '/'.$this->routePrefix().'/connect/'.$key;
    }

    /**
     * Resolve and validate the redirect URI.
     *
     * Xero requires an exact match against a URI registered on the app, and
     * requires https -- with the single exception of http://localhost.
     * http://127.0.0.1 is explicitly rejected by Xero, and its own error for
     * that is vague, so it gets a dedicated message here.
     */
    public function redirectUri(): string
    {
        $uri = (string) ($this->get('redirect_uri') ?? '');

        if ($uri === '') {
            $name = $this->routeName('callback');

            if (! Route::has($name)) {
                throw XeroConfigurationException::redirectUriNotSet();
            }

            $uri = route($name);
        }

        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        if ($scheme === 'https') {
            return $uri;
        }

        if ($host === '127.0.0.1' || $host === '[::1]' || $host === '::1') {
            throw XeroConfigurationException::loopbackRedirectUri($uri);
        }

        if ($scheme === 'http' && $host === 'localhost') {
            return $uri;
        }

        throw XeroConfigurationException::insecureRedirectUri($uri);
    }

    /**
     * Without offline_access Xero issues no refresh token at all, and the
     * connection silently dies 30 minutes after it is created. Fail now.
     */
    public function assertOfflineAccess(): void
    {
        if (! Scopes::has($this->scopes(), Scopes::OFFLINE_ACCESS)) {
            throw XeroConfigurationException::offlineAccessMissing();
        }
    }

    /** Validates everything needed before sending someone to Xero. */
    public function assertReadyToConnect(): void
    {
        $this->clientId();
        $this->clientSecret();
        $this->redirectUri();
        $this->assertOfflineAccess();
    }

    /**
     * Per-connection invoice defaults: named connections inherit the
     * 'default' block, and an explicit key wins even when set to null.
     *
     * @return array<string, mixed>
     */
    public function connectionDefaults(string $key): array
    {
        $all = (array) $this->get('connections', []);

        return array_replace(
            (array) ($all['default'] ?? []),
            (array) ($all[$key] ?? []),
        );
    }

    public function userAgent(): string
    {
        $configured = $this->get('http.user_agent');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Str::of((string) config('app.name', 'Laravel'))
            ->trim()
            ->append(' (peoplelogy/laravel-xero-bridge)')
            ->value();
    }
}
