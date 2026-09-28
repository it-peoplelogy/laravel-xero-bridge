<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A webhook arrived with a bad or missing signature and was rejected with a
 * 401.
 *
 * Expected during Xero's intent-to-receive check, which deliberately sends
 * incorrectly-signed payloads to confirm they are refused. A steady stream
 * outside that window is worth alerting on.
 */
class XeroWebhookSignatureFailed
{
    use Dispatchable;

    public function __construct(
        public readonly ?string $signature,
        public readonly int $bodyLength,
        public readonly ?string $ip,
    ) {}
}
