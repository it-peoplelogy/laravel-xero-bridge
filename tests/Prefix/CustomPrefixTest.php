<?php

declare(strict_types=1);

/*
| Boots with xero-bridge.routes.prefix = 'accounting' (see
| tests/Support/CustomPrefixTestCase.php) to prove the prefix is genuinely
| read from config rather than hardcoded anywhere.
*/

it('registers the routes under the configured prefix', function () {
    expect(route('xero-bridge.connect', ['key' => 'default']))
        ->toContain('/accounting/connect/default');

    $this->get('/accounting/connect')->assertRedirect();

    // And the default prefix is gone.
    $this->get('/xero/connect')->assertNotFound();
});
