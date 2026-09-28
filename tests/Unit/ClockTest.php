<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Peoplelogy\XeroBridge\Support\Clock;

/**
 * Regression tests for a bug that is INVISIBLE on Carbon 3 and breaks badly
 * on Carbon 2, which Laravel 11 still allows (`^2.72.6|^3.8.4`).
 *
 * Carbon 2 keeps separate test-now state for Carbon and CarbonImmutable, so
 * CarbonImmutable::now() ignores Carbon::setTestNow() entirely -- including
 * the call that RELEASES a frozen clock. Token expiry maths then compares two
 * different clocks and quietly returns the wrong answer.
 */
it('follows the framework clock when time is frozen', function () {
    Carbon::setTestNow('2030-06-01 12:00:00');

    expect(Clock::now()->toIso8601String())->toBe('2030-06-01T12:00:00+00:00')
        ->and(Clock::now()->toIso8601String())->toBe(now()->toIso8601String());
});

it('follows the framework clock when time is released', function () {
    Carbon::setTestNow('2030-06-01 12:00:00');
    Carbon::setTestNow();

    // The margin is generous on purpose: the assertion is "tracks real time",
    // not "to the millisecond".
    expect(Clock::now()->diffInSeconds(now(), absolute: true))->toBeLessThan(5);
});

it('returns an immutable instance', function () {
    expect(Clock::now())->toBeInstanceOf(CarbonImmutable::class);
});

it('reflects travel through the framework helpers', function () {
    $this->travelTo('2031-01-02 03:04:05');

    expect(Clock::now()->toDateTimeString())->toBe('2031-01-02 03:04:05');

    $this->travelBack();
});

it('decides token expiry against the frozen clock', function () {
    // The end-to-end symptom. On Carbon 2 with a direct CarbonImmutable::now()
    // this returned the wrong answer, so `xero-bridge:refresh-tokens` reported
    // a stale token as "still fresh" and never refreshed anything.
    Carbon::setTestNow('2030-06-01 12:00:00');

    $connection = connection(['expires_at' => now()->addMinutes(5)]);

    expect($connection->isExpired())->toBeFalse()
        ->and($connection->expiresWithin(1800))->toBeTrue()
        ->and($connection->expiresWithin(60))->toBeFalse();
});
