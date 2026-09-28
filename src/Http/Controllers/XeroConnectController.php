<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Peoplelogy\XeroBridge\OAuth\AuthorizationUrlBuilder;
use Peoplelogy\XeroBridge\OAuth\OAuthStateStore;
use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * Starts the consent flow.
 *
 * Everything that can be wrong in .env is validated HERE, before the user is
 * sent to Xero -- a missing offline_access scope or a 127.0.0.1 redirect URI
 * otherwise surfaces as a confusing failure much later.
 */
final class XeroConnectController
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly AuthorizationUrlBuilder $urls,
        private readonly OAuthStateStore $state,
    ) {}

    public function __invoke(Request $request, ?string $key = null): RedirectResponse
    {
        // An absent key means the default connection, so single-organisation
        // apps never have to think about connection names.
        $key = $key ?: $this->config->defaultConnection();

        // Throws XeroConfigurationException with an actionable message.
        $this->config->assertReadyToConnect();

        $returnTo = $this->config->get('routes.allow_return_to', false)
            ? $this->safeReturnTo($request->query('return_to'))
            : null;

        $state = $this->state->put($key, $returnTo);

        return redirect()->away($this->urls->build($state));
    }

    /**
     * Only same-host absolute paths. An unvalidated redirect target on an
     * authenticated route is an open redirect.
     */
    private function safeReturnTo(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // "//evil.test" is protocol-relative and would leave the host.
        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        return $value;
    }
}
