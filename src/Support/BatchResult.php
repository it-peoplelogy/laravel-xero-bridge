<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;

/**
 * The outcome of a bulk write sent with summarizeErrors=false.
 *
 * That flag makes Xero return HTTP 200 EVEN WHEN SOME ITEMS FAILED, with a
 * per-element StatusAttributeString of OK, WARNING or ERROR. So a bulk call
 * must not be judged by its status code, and the failures have to be dug out
 * of the body.
 */
final class BatchResult
{
    /**
     * @param  list<array<string, mixed>>  $items  every element Xero returned, in order
     * @param  list<array<string, mixed>>  $submitted  what was sent, index-aligned
     */
    private function __construct(
        private readonly array $items,
        private readonly array $submitted,
    ) {}

    /**
     * @param  array<array-key, array<string, mixed>>  $items
     * @param  array<array-key, array<string, mixed>>  $submitted
     */
    public static function make(array $items, array $submitted = []): self
    {
        return new self(array_values($items), array_values($submitted));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function successful(): array
    {
        return $this->withStatus('OK');
    }

    /**
     * Warnings are successes that carry a caveat -- a CurrencyRate warning,
     * for instance. They must not be silently discarded.
     *
     * @return list<array<string, mixed>>
     */
    public function warned(): array
    {
        return $this->withStatus('WARNING');
    }

    /** @return list<array<string, mixed>> */
    public function failed(): array
    {
        return $this->withStatus('ERROR');
    }

    public function hasFailures(): bool
    {
        return $this->failed() !== [];
    }

    /**
     * Every validation message across the failed elements.
     *
     * Reads BOTH `Message` and `Description`: Xero's own documentation uses
     * one in one example of this feature and the other in another.
     *
     * @return list<string>
     */
    public function errorMessages(): array
    {
        $messages = [];

        foreach ($this->failed() as $item) {
            foreach ((array) ($item['ValidationErrors'] ?? []) as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $message = $error['Message'] ?? $error['Description'] ?? null;

                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /** @return list<string> */
    public function warningMessages(): array
    {
        $messages = [];

        foreach ($this->warned() as $item) {
            foreach ((array) ($item['Warnings'] ?? []) as $warning) {
                if (is_array($warning) && isset($warning['Message']) && is_string($warning['Message'])) {
                    $messages[] = $warning['Message'];
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /** What was sent at this index, for reporting "row 3 failed because...". */
    public function submittedAt(int $index): ?array
    {
        return $this->submitted[$index] ?? null;
    }

    public function throwIfAnyFailed(): self
    {
        if (! $this->hasFailures()) {
            return $this;
        }

        $messages = $this->errorMessages();

        throw (new XeroValidationException(sprintf(
            '%d of %d items were rejected by Xero: %s',
            count($this->failed()),
            count($this->items),
            $messages === [] ? 'no detail supplied' : implode('; ', $messages),
        )))->withValidationErrors($messages);
    }

    /** @return list<array<string, mixed>> */
    private function withStatus(string $status): array
    {
        return array_values(array_filter(
            $this->items,
            static function (array $item) use ($status): bool {
                $actual = strtoupper((string) ($item['StatusAttributeString'] ?? 'OK'));

                return $actual === $status;
            },
        ));
    }
}
