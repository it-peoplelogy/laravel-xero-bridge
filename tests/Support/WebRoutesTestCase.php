<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * The package default middleware is ['web', 'auth'], and the routes are
 * registered during boot -- so a Pest beforeEach() runs far too late to
 * change it. Overriding it here, before the application boots, is the only
 * point at which it takes effect.
 *
 * `auth` is dropped because these tests are about the OAuth mechanics, not
 * the host's authentication. `web` is kept because the flow genuinely needs
 * a session to hold the OAuth state. That the default includes `auth` is
 * asserted separately, in tests/Unit/ConfigTest.php.
 */
class WebRoutesTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.routes.middleware', ['web']);
    }
}
