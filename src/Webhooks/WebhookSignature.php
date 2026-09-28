<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Webhooks;

/**
 * Xero's webhook signature.
 *
 * base64(HMAC-SHA256(RAW REQUEST BODY, signing key)), compared with
 * hash_equals.
 *
 * "Raw" is load-bearing: hashing a re-encoded body (json_encode of
 * $request->all(), say) changes whitespace and key order and the signature
 * will never match. It must be the bytes exactly as they arrived.
 */
final class WebhookSignature
{
    public const HEADER = 'x-xero-signature';

    public static function compute(string $rawBody, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $key, true));
    }

    /**
     * Constant-time comparison. An unconfigured key is never treated as a
     * pass -- it fails closed.
     */
    public static function isValid(string $rawBody, ?string $signature, ?string $key): bool
    {
        if ($key === null || $key === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::compute($rawBody, $key), $signature);
    }
}
