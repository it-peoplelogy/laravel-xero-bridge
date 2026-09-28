<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

use Peoplelogy\XeroBridge\Support\XeroConfig;

final class AuthorizationUrlBuilder
{
    public function __construct(private readonly XeroConfig $config) {}

    /**
     * Build the URL the user is redirected to at login.xero.com.
     *
     * PHP_QUERY_RFC3986 matters: the default encoding turns the spaces
     * between scopes into "+", and Xero wants "%20".
     */
    public function build(string $state): string
    {
        $this->config->assertReadyToConnect();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => $this->config->scopes(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->config->endpoint('authorize').'?'.$query;
    }
}
