<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Illuminate\Support\Str;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;

/**
 * Keys for Xero's `Idempotency-Key` header.
 *
 * TWO THINGS TO KNOW, both of which shape what the package can promise:
 *
 *  1. Xero retains a key for SIX MINUTES only. That is enough to protect an
 *     immediate transient-network retry, and nowhere near enough to protect
 *     a queued job retried on any realistic backoff. Consumers must hold
 *     their own dedupe state -- typically by persisting the returned
 *     InvoiceID and making the job a no-op once it is set.
 *  2. The limit is 128 characters. Xero's own documentation recommends
 *     "concatenate four GUIDs", which is 4 x 36 = 144 with hyphens and
 *     therefore violates Xero's own limit. Stripping the hyphens gives
 *     exactly 128.
 */
final class IdempotencyKey
{
    public const MAX_LENGTH = 128;

    /** How long Xero remembers a key, in seconds. */
    public const RETENTION_SECONDS = 360;

    /** A single hyphen-stripped UUIDv4 with a short prefix: 35 characters. */
    public static function generate(string $prefix = 'xb'): string
    {
        return self::truncate($prefix.'_'.str_replace('-', '', (string) Str::uuid()));
    }

    /**
     * Xero's four-GUID recommendation, hyphen-stripped so it lands at exactly
     * the 128-character ceiling. Cannot carry a prefix.
     */
    public static function xeroStyle(): string
    {
        $key = '';

        for ($i = 0; $i < 4; $i++) {
            $key .= str_replace('-', '', (string) Str::uuid());
        }

        return $key;
    }

    /**
     * A deterministic key from parts the caller already has, so an in-process
     * retry of the same logical operation reuses it.
     */
    public static function for(string ...$parts): string
    {
        return self::truncate('xb_'.hash('sha256', implode('|', $parts)));
    }

    public static function assertValid(string $key): void
    {
        if ($key === '') {
            throw new XeroBridgeException('An idempotency key cannot be empty.');
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new XeroBridgeException(sprintf(
                'Idempotency key is %d characters; Xero rejects anything over %d.',
                strlen($key),
                self::MAX_LENGTH,
            ));
        }
    }

    private static function truncate(string $key): string
    {
        return substr($key, 0, self::MAX_LENGTH);
    }
}
