<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice was created in Xero.
 *
 * Listeners should persist $invoiceId against their own record. That, not
 * the idempotency key, is the real defence against duplicates: Xero retains
 * an idempotency key for only six minutes, which no realistic queue backoff
 * stays inside.
 */
class InvoiceCreated
{
    use Dispatchable;

    /** @param array<string, mixed> $invoice */
    public function __construct(
        public readonly string $connectionKey,
        public readonly array $invoice,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function invoiceId(): ?string
    {
        $id = $this->invoice['InvoiceID'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function invoiceNumber(): ?string
    {
        $number = $this->invoice['InvoiceNumber'] ?? null;

        return is_string($number) ? $number : null;
    }

    public function status(): ?string
    {
        $status = $this->invoice['Status'] ?? null;

        return is_string($status) ? $status : null;
    }
}
