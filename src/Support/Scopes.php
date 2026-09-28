<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

/**
 * Xero scopes are a space-separated string, additive across authorisations,
 * and cannot be removed from an existing token without revoking it. Comparing
 * granted scopes against required ones is therefore how the package tells
 * "needs re-consent" apart from "needs a refresh".
 */
final class Scopes
{
    /** Scope without which Xero issues no refresh token at all. */
    public const OFFLINE_ACCESS = 'offline_access';

    /** @return list<string> */
    public static function parse(string|array|null $scopes): array
    {
        if ($scopes === null) {
            return [];
        }

        $parts = is_array($scopes)
            ? $scopes
            : (preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }

    public static function normalize(string|array|null $scopes): string
    {
        return implode(' ', self::parse($scopes));
    }

    public static function has(string|array|null $scopes, string $scope): bool
    {
        return in_array($scope, self::parse($scopes), true);
    }

    /**
     * Scopes present in $required but absent from $granted.
     *
     * @return list<string>
     */
    public static function missing(string|array|null $granted, string|array|null $required): array
    {
        return array_values(array_diff(self::parse($required), self::parse($granted)));
    }
}
