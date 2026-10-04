<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Illuminate\Testing\TestResponse;
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;

/**
 * A signed webhook delivery to an arbitrary URI, for the suites that move the
 * route.
 *
 * A class rather than more global functions: tests/Feature/WebhookTest.php
 * already declares postWebhook(), signed() and eventsPayload() globally, and
 * every test file shares one PHP process, so a second declaration of any
 * name is fatal.
 */
final class SignedWebhook
{
    /** Signed with the key the base TestCase configures. */
    public const KEY = 'test-webhook-key';

    /**
     * Posts RAW bytes, as Xero does. postJson() would re-encode the body and
     * the signature would no longer match it.
     */
    public static function post(string $uri, ?string $raw = null): TestResponse
    {
        $raw ??= self::body();

        return test()->call(
            'POST',
            $uri,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_XERO_SIGNATURE' => WebhookSignature::compute($raw, self::KEY),
            ],
            $raw,
        );
    }

    /** One invoice event for the tenant the connection() helper stores. */
    public static function body(): string
    {
        return (string) json_encode([
            'events' => [[
                'resourceUrl' => 'https://api.xero.com/api.xro/2.0/Invoices/inv-1',
                'resourceId' => 'inv-1',
                'eventDateUtc' => '2026-09-28T01:15:39.902',
                'eventType' => 'UPDATE',
                'eventCategory' => 'INVOICE',
                'tenantId' => 'tenant-1',
                'tenantType' => 'ORGANISATION',
            ]],
            'firstEventSequence' => 1,
            'lastEventSequence' => 1,
            'entropy' => 'S0m3r4Nd0mt3xt',
        ]);
    }
}
