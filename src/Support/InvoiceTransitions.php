<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

/**
 * Xero's documented invoice status transitions.
 *
 * There is NO HTTP DELETE for an invoice: both deleting and voiding are a
 * POST that changes Status. Which one is legal depends entirely on where the
 * invoice currently is:
 *
 *   DRAFT      -> DRAFT, SUBMITTED, AUTHORISED, DELETED
 *   SUBMITTED  -> SUBMITTED, AUTHORISED, DRAFT, DELETED
 *   AUTHORISED -> AUTHORISED, VOIDED
 *   PAID       -> nothing
 *
 * Checking this locally lets the package fail with a message naming the
 * current status, instead of passing an illegal transition to Xero and
 * relaying whatever it says back.
 */
final class InvoiceTransitions
{
    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const AUTHORISED = 'AUTHORISED';

    public const PAID = 'PAID';

    public const VOIDED = 'VOIDED';

    public const DELETED = 'DELETED';

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        self::DRAFT => [self::DRAFT, self::SUBMITTED, self::AUTHORISED, self::DELETED],
        self::SUBMITTED => [self::SUBMITTED, self::AUTHORISED, self::DRAFT, self::DELETED],
        self::AUTHORISED => [self::AUTHORISED, self::VOIDED],
        self::PAID => [],
        self::VOIDED => [],
        self::DELETED => [],
    ];

    public static function allows(string $from, string $to): bool
    {
        return in_array(
            strtoupper($to),
            self::ALLOWED[strtoupper($from)] ?? [],
            true,
        );
    }

    /** DELETED is reachable from DRAFT *and* SUBMITTED, not drafts alone. */
    public static function isDeletable(string $status): bool
    {
        return self::allows($status, self::DELETED);
    }

    public static function isVoidable(string $status): bool
    {
        return self::allows($status, self::VOIDED);
    }

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return self::ALLOWED[strtoupper($status)] ?? [];
    }
}
