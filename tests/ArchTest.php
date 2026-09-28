<?php

declare(strict_types=1);

/*
| These use only the arch API that exists identically in pest-plugin-arch 3, 4
| and 5 -- the CI matrix resolves all three. In particular do NOT use
| arch()->preset()->laravel(): the preset API changed shape between arch 3 and
| 4/5 and would pass on one leg while failing on another.
*/

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('the package never reads env() outside the config file')
    // env() returns null once a consuming app runs `php artisan config:cache`.
    // In a package that reads XERO_CLIENT_SECRET, an env() call in runtime code
    // is a production outage waiting for the first deploy that caches config.
    ->expect('Peoplelogy\XeroBridge')
    ->not->toUse(['env']);
