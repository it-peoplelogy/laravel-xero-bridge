<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois;

/**
 * The identifier a TIN is validated against.
 *
 * LHDN publishes a CLOSED allow-list for this parameter, so the type system
 * enforces it and an unknown value never costs a request against a 60-per-
 * minute budget.
 *
 * Note the deliberate asymmetry with the TIN itself, which is NOT validated
 * anywhere in this package. LHDN publishes no TIN format at all: the parameter
 * table types it as "Number" while its own example, C25845632020, is not one.
 * Malaysian TINs carry letter prefixes that vary by taxpayer class, so any
 * regex invented here would eventually reject a valid TIN and there would be
 * no way for the caller to override it. The allow-list is enforced because it
 * is published; the TIN is passed through untouched because it is not.
 */
enum IdType: string
{
    case NRIC = 'NRIC';

    case PASSPORT = 'PASSPORT';

    case BRN = 'BRN';

    case ARMY = 'ARMY';

    /**
     * Accept a case or its string name, so callers can pass whatever they hold.
     *
     * Case-insensitive because the value arrives from a form, a spreadsheet
     * import or a database column at least as often as from a constant, and
     * "brn" failing where "BRN" succeeds is a support ticket rather than a
     * safety feature. The wire value is always the canonical uppercase form.
     */
    public static function coerce(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $candidate = strtoupper(trim($value));

        return self::tryFrom($candidate) ?? throw MyInvoisException::unknownIdType($value);
    }

    /** Human wording, for a console dropdown or a validation message. */
    public function label(): string
    {
        return match ($this) {
            self::NRIC => 'NRIC (Malaysian identity card)',
            self::PASSPORT => 'Passport number',
            self::BRN => 'Business registration number (SSM)',
            self::ARMY => 'Army number',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
