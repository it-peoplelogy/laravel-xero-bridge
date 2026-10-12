<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois\Facades;

use Illuminate\Support\Facades\Facade;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;

/**
 * @method static bool validate(string $tin, \Peoplelogy\XeroBridge\MyInvois\IdType|string $idType, string $idValue)
 * @method static MyInvoisClient for(\Illuminate\Database\Eloquent\Model $owner)
 * @method static array|null lastRateLimit()
 * @method static string|null lastCorrelationId()
 * @method static void forgetToken()
 *
 * @see MyInvoisClient
 *
 * Deliberately NOT registered as a global alias in composer.json. The Xero side
 * earns one because it is what the package is for; a Malaysia-only module that
 * is off by default should not occupy the global name `MyInvois` in every
 * application that installs this package. Import it, or type-hint
 * MyInvoisClient and let the container resolve it.
 */
class MyInvois extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MyInvoisClient::class;
    }
}
