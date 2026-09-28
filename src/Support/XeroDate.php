<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Xero's date formats, which differ between what it sends and what it takes.
 *
 * READING: JSON responses carry .NET dates -- "/Date(1439434356790)/" or
 * "/Date(1419937200000+0000)/" -- milliseconds since the epoch. Some fields
 * also have an ISO-8601 sibling (DateString, DueDateString, UpdatedDateUTCString).
 *
 * WRITING: a plain "YYYY-MM-DD" for Payments and invoice dates, and the
 * DateTime(y, m, d) literal inside a `where` filter.
 *
 * The package deliberately does NOT rewrite response bodies. A converter
 * would change types under the caller, break json_encode round-trips into
 * their own storage, and have to arbitrate whenever the *String sibling
 * disagrees with the .NET value. This helper is offered instead.
 */
final class XeroDate
{
    /** Parse either .NET or ISO-8601. Returns null for anything unusable. */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // "/Date(1439434356790)/" or "/Date(1419937200000+0000)/". The offset
        // is metadata about the original entry, not an adjustment to apply --
        // the milliseconds are already UTC.
        if (preg_match('#^/Date\((-?\d+)([+-]\d{4})?\)/$#', $value, $matches) === 1) {
            return CarbonImmutable::createFromTimestampMs((int) $matches[1], 'UTC');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read a date from a Xero payload, preferring the ISO sibling field when
     * Xero supplied one.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload, string $field): ?CarbonImmutable
    {
        return self::parse($payload[$field.'String'] ?? null)
            ?? self::parse($payload[$field] ?? null);
    }

    /**
     * The plain YYYY-MM-DD form Xero wants on writes.
     *
     * Accepts a value round-tripped straight out of a read, so a payment read
     * back and re-posted does not fail with a message that never mentions
     * dates.
     */
    public static function toApiDate(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            // format(), not a UTC conversion: a Malaysian date must stay the
            // local calendar date, or an invoice can land in the wrong period.
            return $value->format('Y-m-d');
        }

        $parsed = self::parse($value);

        return $parsed?->format('Y-m-d');
    }

    /**
     * The DateTime(y, m, d) literal used inside a `where` filter.
     */
    public static function toFilterLiteral(DateTimeInterface|string $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : self::parse($value);

        if ($date === null) {
            throw new \InvalidArgumentException('Could not read a date from the given value.');
        }

        return sprintf('DateTime(%d, %d, %d)', $date->year, $date->month, $date->day);
    }

    /**
     * The If-Modified-Since header format: UTC, to the second, and with NO
     * trailing Z.
     */
    public static function toModifiedSinceHeader(DateTimeInterface|string $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : self::parse($value);

        if ($date === null) {
            throw new \InvalidArgumentException('Could not read a date from the given value.');
        }

        return $date->utc()->format('Y-m-d\TH:i:s');
    }
}
