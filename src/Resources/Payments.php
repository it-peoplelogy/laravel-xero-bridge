<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Resources;

use DateTimeInterface;
use InvalidArgumentException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Support\XeroDate;
use Psr\Log\LoggerInterface;

/**
 * Payments against invoices.
 *
 * NOTE there is deliberately no update(). Xero payments cannot be modified,
 * only created and deleted -- so delete() exists instead, and the void()
 * error on Invoices points at it.
 */
class Payments extends Resource
{
    protected function endpoint(): string
    {
        return 'Payments';
    }

    /**
     * Apply a payment to an invoice.
     *
     * The invoice must be AUTHORISED; Xero flips it to PAID automatically
     * once it is fully paid.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    public function create(array $payment): array
    {
        $this->assertNotBatch($payment);
        $this->assertPayable($payment);

        if (isset($payment['Date'])) {
            // Xero wants a plain Y-m-d on input even though it RETURNS
            // /Date(...)/, so a payment read back and re-posted would
            // otherwise fail with a message that never mentions dates.
            $payment['Date'] = XeroDate::toApiDate($payment['Date']);
        }

        $created = $this->unwrapFirst(
            $this->client->post($this->endpoint(), ['Payments' => [$payment]])
        ) ?? [];

        $this->reportWarnings($created);

        return $created;
    }

    /**
     * The common case, with the fields Xero requires.
     *
     * @return array<string, mixed>
     */
    public function createForInvoice(
        string $invoiceId,
        float|int|string $amount,
        ?string $accountCode = null,
        DateTimeInterface|string|null $date = null,
        array $extra = [],
    ): array {
        $accountCode ??= $this->defaults->get('payment_account_code');

        if ($accountCode === null || $accountCode === '') {
            throw new InvalidArgumentException(
                'A payment needs an account. Pass an account code, or set one on the connection. '
                .'Discover the valid ones with settings()->paymentAccounts() -- Xero only accepts '
                .'an account of type BANK or one with "enable payments to this account" switched on.'
            );
        }

        return $this->create(array_merge([
            'Invoice' => ['InvoiceID' => $invoiceId],
            'Account' => ['Code' => $accountCode],
            'Amount' => $amount,
            'Date' => XeroDate::toApiDate($date ?? now()),
        ], $extra));
    }

    /** @return array<string, mixed>|null */
    public function find(string $paymentId): ?array
    {
        return $this->unwrapFirst(
            $this->client->get($this->endpoint().'/'.$this->pathSegment($paymentId))
        );
    }

    /**
     * @param  array<string, mixed>  $query  e.g. ['where' => 'Status=="AUTHORISED"']
     * @return list<array<string, mixed>>
     */
    public function list(array $query = []): array
    {
        return $this->unwrap($this->client->get($this->endpoint(), $query));
    }

    /**
     * Reverse a payment.
     *
     * Payments created through BatchPayments or Receipts cannot be removed
     * this way -- Xero rejects those.
     *
     * @return array<string, mixed>
     */
    public function delete(string $paymentId): array
    {
        return $this->unwrapFirst($this->client->post(
            $this->endpoint().'/'.$this->pathSegment($paymentId),
            ['Payments' => [['Status' => 'DELETED']]],
        )) ?? [];
    }

    /** @param array<string, mixed> $payment */
    private function assertPayable(array $payment): void
    {
        $account = $payment['Account'] ?? [];

        $hasAccount = is_array($account)
            && ((isset($account['AccountID']) && $account['AccountID'] !== '')
                || (isset($account['Code']) && $account['Code'] !== ''));

        if (! $hasAccount) {
            throw new InvalidArgumentException(
                'A payment needs Account.AccountID or Account.Code. The account must be of type '
                .'BANK, or have "enable payments to this account" switched on -- list the valid '
                .'ones with settings()->paymentAccounts().'
            );
        }

        $target = ['Invoice', 'CreditNote', 'Prepayment', 'Overpayment'];

        foreach ($target as $key) {
            if (isset($payment[$key]) && is_array($payment[$key]) && $payment[$key] !== []) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'A payment must target an Invoice, CreditNote, Prepayment or Overpayment.'
        );
    }

    /** @param array<string, mixed> $payment */
    private function assertNotBatch(array $payment): void
    {
        if (isset($payment['Invoices'])) {
            throw new XeroBridgeException(
                'POST /Payments applies ONE payment to ONE invoice. To settle several invoices '
                .'with a single transaction, use Xero\'s /BatchPayments endpoint via '
                .'XeroBridge::request().'
            );
        }
    }

    /**
     * A CurrencyRate validation WARNING can arrive on a successful 200. The
     * client only raises failures, so without this the warning is invisible
     * -- and it can mean the posted foreign-currency amount is wrong.
     *
     * @param  array<string, mixed>  $payment
     */
    private function reportWarnings(array $payment): void
    {
        $warnings = [];

        foreach ((array) ($payment['Warnings'] ?? []) as $warning) {
            if (is_array($warning) && isset($warning['Message']) && is_string($warning['Message'])) {
                $warnings[] = $warning['Message'];
            }
        }

        if ($warnings === []) {
            return;
        }

        app(LoggerInterface::class)->warning(
            'xero-bridge: Xero accepted the payment but returned warnings.',
            ['connection' => $this->connectionKey, 'warnings' => $warnings],
        );
    }
}
