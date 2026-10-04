<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Peoplelogy\XeroBridge\Commands\StatusCommand;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;

/**
 * After an APP_KEY rotation without APP_PREVIOUS_KEYS, the stored tokens can no
 * longer be decrypted. Up to 1.4.x, XeroConnection::isUsable() -- the guard the
 * docs tell every webhook listener to use -- threw a DecryptException, and
 * xero-bridge:status died on it with a stack trace. Both must now answer.
 *
 * The rotation is simulated by giving Eloquent's encrypted cast a different key
 * after the row is saved, which is exactly what a new APP_KEY does.
 */
function rotateAppKeyAfterSaving(): XeroConnection
{
    connection();

    Model::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    return XeroConnection::query()->where('key', 'default')->sole();
}

afterEach(function () {
    // The static encrypter outlives the test otherwise.
    Model::encryptUsing(null);
});

it('reports an undecryptable connection as unusable instead of throwing', function () {
    $connection = rotateAppKeyAfterSaving();

    expect($connection->isUsable())->toBeFalse();
});

it('keeps reporting status when the stored tokens cannot be decrypted, and exits 1', function () {
    rotateAppKeyAfterSaving();

    $exit = Artisan::call('xero-bridge:status');
    // Collapsed, so the table's padding and a CI runner's narrower wrapping
    // cannot split what is asserted.
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    // 1, as for missing configuration: every call through the connection
    // fails with a configuration error, and the cure -- APP_PREVIOUS_KEYS --
    // is the deploy owner's. 1.4.x exited 1 here too, dying on the
    // DecryptException; monitoring must not turn green on a dead integration.
    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        // Never "valid": the expiry is in the future, and no call can use it.
        ->and($output)->toContain('| default | Acme Sdn Bhd | unreadable |')
        ->not->toContain('| valid |')
        ->toContain('cannot be decrypted')
        ->toContain('APP_PREVIOUS_KEYS')
        ->not->toContain('DecryptException');

    expect(Artisan::call('xero-bridge:status', ['--strict' => true]))->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('leaves a connection that must be re-authorised anyway to exit 2', function () {
    // Reconnecting replaces the tokens, so that is the one thing to say.
    connection(['invalidated_at' => now(), 'invalidated_reason' => 'invalid_grant']);
    Model::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    $exit = Artisan::call('xero-bridge:status');
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($exit)->toBe(StatusCommand::EXIT_NEEDS_REAUTH)
        ->and($output)->toContain('| default | Acme Sdn Bhd | reconnect |')
        ->not->toContain('unreadable');
});

it('marks the connection unreadable in the JSON report', function () {
    rotateAppKeyAfterSaving();

    $exit = Artisan::call('xero-bridge:status', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($report['connections'][0])
        ->toMatchArray(['key' => 'default', 'usable' => false, 'tokens_readable' => false]);
});

it('names both ways out when the stored tokens are used', function () {
    $connection = rotateAppKeyAfterSaving();

    try {
        app(TokenManager::class)->readToken($connection, 'refresh_token');
        $this->fail('Expected XeroConfigurationException.');
    } catch (XeroConfigurationException $e) {
        // The same two remedies the status warning gives, the cheaper one
        // first. The host is APP_URL's, which differs between machines.
        expect($e->getMessage())
            ->toStartWith(
                'The stored Xero tokens for connection [default] cannot be decrypted. This normally means '
                .'APP_KEY changed since they were saved. Put the old key in APP_PREVIOUS_KEYS, or re-authorise at '
            )
            ->toEndWith('/xero/connect/default.')
            ->and($e->connectionKey())->toBe('default');
    }
});

it('marks a connection with readable tokens as readable', function () {
    connection();

    Artisan::call('xero-bridge:status', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['connections'][0]['tokens_readable'])->toBeTrue();
});
