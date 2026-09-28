<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Facades;

use Illuminate\Support\Facades\Facade;
use Peoplelogy\XeroBridge\XeroBridgeManager;

/**
 * @method static \Peoplelogy\XeroBridge\XeroBridgeManager connection(?string $key = null)
 * @method static string key()
 * @method static \Peoplelogy\XeroBridge\Client\XeroHttpClient client()
 * @method static \Peoplelogy\XeroBridge\OAuth\TokenManager tokens()
 * @method static \Illuminate\Support\Collection connections()
 * @method static string connectUrl(?string $key = null)
 * @method static array request(string $method, string $uri, array $payload = [], array $headers = [])
 * @method static \Illuminate\Http\Client\Response raw(string $method, string $uri, array $payload = [], array $headers = [])
 * @method static \Peoplelogy\XeroBridge\Support\ConnectionDefaults defaults()
 * @method static \Peoplelogy\XeroBridge\XeroBridgeManager withDefaults(array $overrides)
 * @method static void redirectAfterConnectUsing(\Closure $callback)
 *
 * @see XeroBridgeManager
 */
class XeroBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return XeroBridgeManager::class;
    }
}
