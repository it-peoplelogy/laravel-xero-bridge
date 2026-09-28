<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Webhooks;

/**
 * A complete webhook payload.
 *
 * All four envelope fields are declared required by Xero's OpenAPI spec, not
 * just `events`.
 */
final class WebhookEnvelope
{
    /** @param list<WebhookEvent> $events */
    private function __construct(
        public readonly array $events,
        public readonly int $firstEventSequence,
        public readonly int $lastEventSequence,
        public readonly string $entropy,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $events = [];

        foreach ((array) ($payload['events'] ?? []) as $event) {
            if (is_array($event)) {
                $events[] = WebhookEvent::fromArray($event);
            }
        }

        return new self(
            events: $events,
            firstEventSequence: (int) ($payload['firstEventSequence'] ?? 0),
            lastEventSequence: (int) ($payload['lastEventSequence'] ?? 0),
            entropy: (string) ($payload['entropy'] ?? ''),
        );
    }

    /**
     * Xero's "intent to receive" validation sends a correctly-signed payload
     * with an EMPTY events array. It must return 2xx and must not blow up,
     * and there is nothing to queue for it.
     */
    public function isIntentToReceive(): bool
    {
        return $this->events === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'events' => array_map(static fn (WebhookEvent $e) => $e->toArray(), $this->events),
            'firstEventSequence' => $this->firstEventSequence,
            'lastEventSequence' => $this->lastEventSequence,
            'entropy' => $this->entropy,
        ];
    }
}
