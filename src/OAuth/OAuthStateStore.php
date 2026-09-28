<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * CSRF state for the authorisation-code flow.
 *
 * A MAP of nonce => {key, return_to, expires}, not a single value. Two
 * reasons, both of which bite in practice with the single-value approach:
 *
 *  1. Opening the connect flow in two browser tabs makes the second overwrite
 *     the first, and the first then fails looking exactly like an attack.
 *  2. The entry carries the connection key. Xero allows one registered
 *     redirect_uri, so the callback URL cannot identify which connection slot
 *     is being filled -- and putting the key in a query string would let an
 *     attacker write a victim's tokens into someone else's slot.
 *
 * Entries are single-use: pull() removes the entry whether or not it matched,
 * which makes callback replay impossible.
 */
final class OAuthStateStore
{
    private const SESSION_KEY = 'xero-bridge.oauth';

    /** The authorisation code itself only lives 5 minutes; 10 is ample. */
    private const TTL_SECONDS = 600;

    private const MAX_ENTRIES = 5;

    public function __construct(private readonly Session $session) {}

    /**
     * Mint a nonce for this connection key and remember it.
     */
    public function put(string $connectionKey, ?string $returnTo = null): string
    {
        $nonce = Str::random(40);

        $entries = $this->prune($this->all());

        $entries[$nonce] = [
            'key' => $connectionKey,
            'return_to' => $returnTo,
            'expires' => time() + self::TTL_SECONDS,
        ];

        // Drop oldest first if a user has somehow opened many flows at once.
        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES, null, true);
        }

        $this->session->put(self::SESSION_KEY, $entries);

        return $nonce;
    }

    /**
     * Consume a state value. Returns null when it is unknown or expired.
     *
     * The matching entry is removed either way, so a state can never be
     * replayed. Comparison uses hash_equals to avoid leaking timing.
     *
     * @return array{key: string, return_to: ?string}|null
     */
    public function pull(?string $nonce): ?array
    {
        $entries = $this->all();
        $match = null;

        foreach ($entries as $candidate => $entry) {
            if ($nonce !== null && hash_equals((string) $candidate, $nonce)) {
                $match = $entry;
                unset($entries[$candidate]);

                break;
            }
        }

        $this->session->put(self::SESSION_KEY, $this->prune($entries));

        if ($match === null || ($match['expires'] ?? 0) < time()) {
            return null;
        }

        return [
            'key' => (string) $match['key'],
            'return_to' => $match['return_to'] ?? null,
        ];
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * Deliberately typed loosely: this is whatever is in the session, which
     * may be stale data written by an older version of the package, so the
     * defensive checks below are real rather than redundant.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $entries = $this->session->get(self::SESSION_KEY, []);

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    private function prune(array $entries): array
    {
        $now = time();

        return array_filter(
            $entries,
            static fn ($entry) => is_array($entry) && ($entry['expires'] ?? 0) >= $now
        );
    }
}
