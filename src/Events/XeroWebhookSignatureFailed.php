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
 *
 * Its listeners run inside the webhook request, before the 401 is sent, so
 * keep them fast or queue them. One that throws -- a queued listener whose
 * push fails included -- is logged and the 401 still goes out.
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
