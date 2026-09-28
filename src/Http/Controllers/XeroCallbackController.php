<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Controllers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Events\XeroConnected;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\IdentityClient;
use Peoplelogy\XeroBridge\OAuth\OAuthStateStore;
use Peoplelogy\XeroBridge\OAuth\TenantInfo;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\XeroBridgeManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Completes the consent flow.
 *
 * Every failure here is a redirect with a readable flash message, never a 500
 * and never a stack trace in the browser: the person looking at it is usually
 * an administrator connecting an accounting system, not a developer.
 */
final class XeroCallbackController
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly OAuthStateStore $state,
        private readonly IdentityClient $identity,
        private readonly ConnectionRepository $connections,
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        // Consume the state first, whatever else happened, so it can never be
        // replayed -- including on the user-cancelled path.
        $entry = $this->state->pull($request->query('state'));

        if ($request->query('error')) {
            return $this->fail($this->describeOAuthError(
                (string) $request->query('error'),
                $request->query('error_description'),
            ));
        }

        if ($entry === null) {
            $this->logger->warning('xero-bridge: OAuth callback with an unknown or expired state.', [
                'ip' => $request->ip(),
            ]);

            return $this->fail(
                'The Xero authorisation could not be verified, because the link expired or your '
                .'session changed. Please start again.'
            );
        }

        $key = $entry['key'];
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->fail('Xero did not return an authorisation code. Please try again.', $entry);
        }

        try {
            $tokens = $this->identity->exchangeAuthorizationCode($code, $this->config->redirectUri());

            if (! $tokens->hasRefreshToken()) {
                // Only possible when offline_access was not granted.
                return $this->fail(
                    'Xero did not return a refresh token, so the connection would stop working '
                    .'after 30 minutes. Add "offline_access" to XERO_SCOPES and connect again.',
                    $entry,
                );
            }

            $tenant = $this->selectTenant($tokens->accessToken, $key);

            $existing = $this->connections->findByKey($key);
            $repointed = $existing !== null && $existing->tenant_id !== $tenant->tenantId;

            $connection = $this->connections->upsert($key, $tenant, $tokens);
        } catch (XeroBridgeException $e) {
            $this->logger->warning('xero-bridge: OAuth callback failed.', $e->context());

            return $this->fail($e->getMessage(), $entry);
        } catch (Throwable $e) {
            $this->logger->error('xero-bridge: unexpected failure in the OAuth callback.', [
                'exception' => $e->getMessage(),
            ]);

            return $this->fail('Could not complete the Xero connection. Please try again.', $entry);
        }

        XeroConnected::dispatch($connection, $repointed);

        return $this->succeed(
            "Connected to the Xero organisation \"{$connection->displayName()}\".",
            $entry,
            $connection,
        );
    }

    /**
     * Choose which organisation this connection points at.
     *
     * /connections returns a BARE ARRAY of every tenant the token can reach,
     * not just the one just authorised -- so several ORGANISATIONs is normal
     * for a returning user and a deterministic rule is required.
     */
    private function selectTenant(string $accessToken, string $key): TenantInfo
    {
        $response = $this->http
            ->withToken($accessToken)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout((int) $this->config->get('tokens.http_timeout', 8))
            ->get($this->config->endpoint('connections'));

        if (! $response->successful()) {
            throw (new XeroBridgeException(
                'Xero accepted the sign-in but the list of organisations could not be read '
                ."(HTTP {$response->status()}). Please try again."
            ))->withResponse($response, $key);
        }

        $organisations = collect((array) $response->json())
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => TenantInfo::fromArray($row))
            ->filter(fn (TenantInfo $tenant) => $tenant->isOrganisation() && $tenant->tenantId !== '')
            ->values();

        if ($organisations->isEmpty()) {
            throw (new XeroBridgeException(
                'No Xero organisation was connected. If you authorised a Practice Manager or '
                .'practice account, please start again and choose an organisation.'
            ))->withConnectionKey($key);
        }

        if ($organisations->count() === 1) {
            return $organisations->first();
        }

        // Prefer an organisation not already claimed by a DIFFERENT key, then
        // the most recently authorised, with the tenant id as a final
        // deterministic tie-break.
        $unclaimed = $organisations->filter(function (TenantInfo $tenant) use ($key) {
            $existing = $this->connections->findByTenantId($tenant->tenantId);

            return $existing === null || $existing->key === $key;
        });

        $candidates = $unclaimed->isNotEmpty() ? $unclaimed : $organisations;

        $chosen = $candidates->sortByDesc(fn (TenantInfo $t) => $t->recencyKey())->first();

        $this->logger->info('xero-bridge: multiple Xero organisations authorised; one was selected.', [
            'connection' => $key,
            'chosen' => $chosen->tenantId,
            'available' => $organisations->map(fn (TenantInfo $t) => $t->tenantId)->all(),
        ]);

        return $chosen;
    }

    private function describeOAuthError(string $error, mixed $description): string
    {
        return match ($error) {
            'access_denied' => 'You cancelled the Xero connection.',
            'invalid_scope' => 'One of the requested Xero permissions is not enabled on this application.',
            'unauthorized_client', 'invalid_client' => 'This application is not authorised by Xero. Check XERO_CLIENT_ID.',
            default => 'Xero refused the authorisation ['.$error.']'
                .(is_string($description) && $description !== '' ? ': '.$description : '.'),
        };
    }

    /** @param array{key: string, return_to: ?string}|null $entry */
    private function fail(string $message, ?array $entry = null): RedirectResponse
    {
        return redirect()->to($this->destination($entry))
            ->with('xero-bridge.error', $message)
            ->with('error', $message);
    }

    /** @param array{key: string, return_to: ?string}|null $entry */
    private function succeed(
        string $message,
        ?array $entry = null,
        ?XeroConnection $connection = null,
    ): RedirectResponse {
        return redirect()->to($this->destination($entry, $connection))
            ->with('xero-bridge.status', $message)
            // Also flashed as `status` so Breeze/Jetstream layouts show it
            // without the host wiring anything up.
            ->with('status', $message);
    }

    /**
     * Precedence, highest first:
     *   1. a closure registered with XeroBridge::redirectAfterConnectUsing()
     *   2. a validated same-host ?return_to= (opt-in)
     *   3. routes.after_connect_route, if that named route exists
     *   4. routes.after_connect_redirect
     *
     * The closure comes first because it is the only option that can decide
     * per connection -- a config file cannot hold a closure once cached, which
     * is the whole reason the hook exists.
     *
     * @param  array{key: string, return_to: ?string}|null  $entry
     */
    private function destination(?array $entry, ?XeroConnection $connection = null): string
    {
        if (($callback = XeroBridgeManager::afterConnectCallback()) !== null) {
            $url = $callback($connection);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (($entry['return_to'] ?? null) !== null) {
            return (string) $entry['return_to'];
        }

        $named = $this->config->get('routes.after_connect_route');

        if (is_string($named) && $named !== '' && Route::has($named)) {
            return route($named);
        }

        return (string) $this->config->get('routes.after_connect_redirect', '/');
    }
}
