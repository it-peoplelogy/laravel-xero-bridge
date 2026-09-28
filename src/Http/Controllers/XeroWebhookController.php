<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Peoplelogy\XeroBridge\Events\XeroWebhookSignatureFailed;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives Xero webhooks.
 *
 * Xero's intent-to-receive rules, all of which this has to satisfy:
 *   - respond within 5 SECONDS with a 2xx
 *   - NO cookies in the response
 *   - 401 for an invalid signature
 * Xero deliberately sends both correctly- and incorrectly-signed payloads
 * during validation, so getting either wrong fails the whole check.
 *
 * The handler therefore does the least possible work: verify, queue, return.
 * Nothing is processed inline.
 */
final class XeroWebhookController
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly BusDispatcher $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        // The RAW bytes, exactly as they arrived. Re-encoding $request->all()
        // changes whitespace and key order, and the signature would never
        // match.
        $raw = $request->getContent();

        $valid = WebhookSignature::isValid(
            $raw,
            $request->header(WebhookSignature::HEADER),
            $this->config->webhookKey(),
        );

        if (! $valid) {
            XeroWebhookSignatureFailed::dispatch(
                $request->header(WebhookSignature::HEADER),
                strlen($raw),
                $request->ip(),
            );

            // Xero expects exactly this for a bad signature. An unconfigured
            // signing key lands here too: it fails closed.
            return new Response('', 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            // Signed by Xero, so it is genuinely from them -- returning
            // anything but a 2xx would count against the subscription.
            $this->logger->critical('xero-bridge: a correctly signed Xero webhook could not be parsed.', [
                'length' => strlen($raw),
            ]);

            return new Response('', 200);
        }

        $envelope = WebhookEnvelope::fromArray($payload);

        // The intent-to-receive ping carries an empty events array. Answer
        // 200 and queue nothing.
        if ($envelope->isIntentToReceive()) {
            return new Response('', 200);
        }

        try {
            $job = new ProcessXeroWebhook($payload);

            if (($queue = $this->config->get('webhooks.queue')) !== null) {
                $job->onQueue((string) $queue);
            }

            if (($connection = $this->config->get('webhooks.connection')) !== null) {
                $job->onConnection((string) $connection);
            }

            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            // Could not queue it, so we have not accepted it. A 500 makes
            // Xero retry rather than dropping the event silently.
            $this->logger->error('xero-bridge: failed to queue a Xero webhook.', [
                'exception' => $e->getMessage(),
            ]);

            return new Response('', 500);
        }

        return new Response('', 200);
    }
}
