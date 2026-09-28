<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * Xero refused the request for insufficient scope.
 *
 * This arrives as a 401 with the header `WWW-Authenticate: insufficent_scope`
 * -- Xero's own spelling, which the package matches alongside the correct
 * one. It must be told apart from an expired token, because the remedy is
 * completely different: a token refresh will never fix it, and retrying the
 * refresh in a loop is how an integration ends up spinning forever. The fix
 * is to widen XERO_SCOPES and have someone consent again.
 *
 * Scopes are additive and cannot be removed from an existing token, so the
 * connection's stored scopes tell you whether it predates a scope addition.
 */
class XeroScopeException extends XeroAuthenticationException
{
    /** @var list<string> */
    protected array $grantedScopes = [];

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return $this->grantedScopes;
    }

    /** @param list<string> $granted */
    public function withGrantedScopes(array $granted): static
    {
        $this->grantedScopes = $granted;

        return $this;
    }

    /** @param list<string> $granted */
    public static function make(string $key, string $connectUrl, array $granted = []): self
    {
        $have = $granted === [] ? 'none recorded' : implode(' ', $granted);

        return (new self(
            "Xero refused the request because connection [{$key}] was not granted a required "
            ."scope. Add it to XERO_SCOPES and authorise again at {$connectUrl} -- refreshing "
            ."the token will not help. Currently granted: {$have}."
        ))->withConnectionKey($key)->withGrantedScopes($granted);
    }
}
