<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The package's single source of "now".
 *
 * Everything in src/ MUST use this rather than calling CarbonImmutable::now()
 * directly, for a reason that is invisible on Carbon 3 and breaks badly on
 * Carbon 2:
 *
 *   Carbon 2 keeps SEPARATE test-now state for Carbon and CarbonImmutable.
 *   Calling Carbon::setTestNow() -- which is what Laravel's travelTo(), the
 *   framework itself and virtually every consuming application's test suite
 *   do -- leaves CarbonImmutable::now() pointing at real time, or worse, at a
 *   stale frozen value it never releases. Token expiry maths then silently
 *   compares two different clocks.
 *
 * Laravel 11 allows `nesbot/carbon ^2.72.6|^3.8.4`, so a consumer on Carbon 2
 * is a supported configuration, not a hypothetical one. Going through the
 * Date facade gives the framework's clock on both majors.
 */
final class Clock
{
    public static function now(): CarbonImmutable
    {
        return Date::now()->toImmutable();
    }
}
