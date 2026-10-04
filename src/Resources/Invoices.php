<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Resources;

use Illuminate\Support\LazyCollection;
use Peoplelogy\XeroBridge\Events\InvoiceCreated;
use Peoplelogy\XeroBridge\Exceptions\InvalidInvoicePayloadException;
use Peoplelogy\XeroBridge\Exceptions\InvalidInvoiceTransitionException;
use Peoplelogy\XeroBridge\Exceptions\InvoiceCannotBeVoidedException;
use Peoplelogy\XeroBridge\Exceptions\UnsafeContactPayloadException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;
use Peoplelogy\XeroBridge\Support\BatchResult;
use Peoplelogy\XeroBridge\Support\IdempotencyKey;
use Peoplelogy\XeroBridge\Support\InvoiceTransitions;
use Psr\Log\LoggerInterface;
use Throwable;

class Invoices extends Resource
{
    /**
     * Set by withContactMutation(), and only ever on the copy it returns.
     *
     * Neither flag is written anywhere else, so nothing has to remember to
     * clear it: the shared instance never carries one.
     */
    private bool $allowContactMutation = false;

    /** Set by replacingLineItems(), and only ever on the copy it returns. */
    private bool $allowLineItemReplacement = false;

    protected function endpoint(): string
    {
        return 'Invoices';
    }

    /**
     * Deliberately opt in to Xero updating the CONTACT RECORD as a side
     * effect of writing an invoice.
     *
     *     XeroBridge::invoices()->withContactMutation()->create($invoice);
     *
     * Returns a COPY with the opt-in set, never $this -- the idiom for() and
     * reference() already use, and for the same reason. XeroBridge::invoices()
     * hands every caller the one instance memoised for that connection for
     * the life of the process, so a flag set on it would outlive the call that
     * asked for it: in a queue worker, or under Octane, later writes that
     * never opted in would go out with the guard off.
     *
     * So the opt-in covers every call made through the returned copy --
     * including every invoice in a createMany() -- and nothing else. It works
     * on either side of for(). Called on a line of its own it changes
     * nothing, and the guard still refuses the payload with nothing sent:
     * chain it. A copy kept in a variable stays opted in for every call made
     * through it, so keep one only while every one of those calls means to
     * rewrite the contact.
     */
    public function withContactMutation(): static
    {
        $clone = clone $this;
        $clone->allowContactMutation = true;

        return $clone;
    }

    /**
     * Deliberately opt in to Xero deleting and recreating line items on
     * update.
     *
     *     XeroBridge::invoices()->replacingLineItems()->update($id, $invoice);
     *
     * Returns a COPY, never $this, exactly like withContactMutation(): the
     * opt-in covers every call made through that copy and cannot reach any
     * other caller of the shared instance. Called on a line of its own it
     * changes nothing, and update() still refuses the lines with nothing sent.
     */
    public function replacingLineItems(): static
    {
        $clone = clone $this;
        $clone->allowLineItemReplacement = true;

        return $clone;
    }

    /**
     * Create one invoice.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public function create(array $invoice, ?string $idempotencyKey = null): array
    {
        $invoice = $this->prepare($invoice);

        $key = $idempotencyKey ?? IdempotencyKey::generate();
        IdempotencyKey::assertValid($key);

        // Claimed BEFORE the request leaves. Xero honours an idempotency key
        // for only six minutes; this is the defence that outlives that.
        $claim = $this->claimWrite('invoice.create', $key);

        try {
            $body = $this->client->post(
                $this->endpoint(),
                ['Invoices' => [$invoice]],
                ['Idempotency-Key' => $key],
            );
        } catch (Throwable $e) {
            // Only a proven non-creation frees the claim. Anything else leaves
            // it pending, because re-sending could duplicate a real invoice.
            $this->releaseWriteOnProvenFailure($claim, $e);

            throw $e;
        }

        $created = $this->unwrapFirst($body) ?? [];

        $this->confirmWrite($claim, $created);

        InvoiceCreated::dispatch($this->connectionKey, $created, $key);

        return $created;
    }

    /**
     * Create several invoices in one request.
     *
     * Sent with summarizeErrors=false, so Xero returns HTTP 200 even when
     * some items fail and the outcome must be read per element -- hence a
     * BatchResult rather than a plain array.
     *
     * Accepts a keyed array too, hence the array_values() normalisation --
     * Xero needs a JSON array here, not an object.
     *
     * NOT covered by the write ledger: no claim is taken, so naming an owner
     * with for() protects nothing here. That is logged rather than thrown --
     * refusing would break every caller already doing it -- and create(),
     * once per invoice, is the protected path.
     *
     * @param  array<array-key, array<string, mixed>>  $invoices
     */
    public function createMany(array $invoices, ?string $idempotencyKey = null): BatchResult
    {
        $prepared = array_map(fn (array $invoice) => $this->prepare($invoice), array_values($invoices));

        $key = $idempotencyKey ?? IdempotencyKey::generate();
        IdempotencyKey::assertValid($key);

        if ($this->hasOwner()) {
            app(LoggerInterface::class)->warning(
                'xero-bridge: createMany() was called on a resource named with for(), but only create() '
                .'is protected by the write ledger. This batch is sent without a claim, so a retry can '
                .'create every invoice in it again. Call create() once per invoice to protect each one.',
                ['connection' => $this->connectionKey, 'invoices' => count($prepared)],
            );
        }

        $body = $this->client->post(
            $this->endpoint(),
            ['Invoices' => $prepared],
            ['Idempotency-Key' => $key],
            ['summarizeErrors' => 'false'],
        );

        $result = BatchResult::make($this->unwrap($body), $prepared);

        // Only the elements Xero actually accepted.
        foreach (array_merge($result->successful(), $result->warned()) as $invoice) {
            InvoiceCreated::dispatch($this->connectionKey, $invoice, $key);
        }

        return $result;
    }

    /**
     * Fetch one invoice. Xero accepts either an InvoiceID GUID or a human
     * InvoiceNumber such as INV-01514.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $idOrNumber): ?array
    {
        $this->assertUsableIdentifier($idOrNumber);

        return $this->unwrapFirst(
            $this->client->get($this->endpoint().'/'.$this->pathSegment($idOrNumber))
        );
    }

    /**
     * One page of invoices.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?InvoiceFilter $filter = null): array
    {
        $filter ??= InvoiceFilter::make();

        return $this->unwrap(
            $this->client->get($this->endpoint(), $filter->toQuery(), $filter->toHeaders())
        );
    }

    /**
     * Every matching invoice, paged lazily.
     *
     * Walks Xero's `pagination` object (page/pageSize/pageCount/itemCount).
     * Xero's docs say the older "keep fetching until a page comes back short"
     * approach "is superseded by the pagination object" -- it always costs
     * one wasted request when the total is an exact multiple of the page
     * size. The short-page check survives only as a fallback for responses
     * that carry no pagination object.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function all(?InvoiceFilter $filter = null, int $maxPages = 1000): LazyCollection
    {
        $filter ??= InvoiceFilter::make();

        return LazyCollection::make(function () use ($filter, $maxPages) {
            $page = $filter->currentPage() ?? 1;
            $pageSize = $filter->currentPageSize() ?? 100;
            $pagesFetched = 0;

            while (true) {
                $scoped = $filter->forPage($page, $pageSize);

                $body = $this->client->get(
                    $this->endpoint(),
                    $scoped->toQuery(),
                    $scoped->toHeaders(),
                );

                $items = $this->unwrap($body);

                foreach ($items as $item) {
                    yield $item;
                }

                $pagesFetched++;

                if ($pagesFetched >= $maxPages) {
                    throw new XeroBridgeException(
                        "Stopped paging Xero invoices after {$maxPages} pages. Narrow the filter, "
                        .'or raise the limit if this many pages is genuinely expected.'
                    );
                }

                $pagination = $body['pagination'] ?? null;

                if (is_array($pagination) && isset($pagination['pageCount'])) {
                    if ($page >= (int) $pagination['pageCount']) {
                        return;
                    }
                } elseif (count($items) < $pageSize) {
                    // Fallback for a response with no pagination object.
                    return;
                }

                if ($items === []) {
                    return;
                }

                $page++;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public function update(string $idOrNumber, array $invoice): array
    {
        $this->assertUsableIdentifier($idOrNumber);

        if (isset($invoice['LineItems']) && ! $this->allowLineItemReplacement) {
            foreach ((array) $invoice['LineItems'] as $line) {
                if (! is_array($line) || ! isset($line['LineItemID'])) {
                    throw InvalidInvoicePayloadException::unsafeLineItems();
                }
            }
        }

        $invoice = $this->guardContact($invoice);

        $body = $this->client->post(
            $this->endpoint().'/'.$this->pathSegment($idOrNumber),
            ['Invoices' => [$invoice]],
        );

        return $this->unwrapFirst($body) ?? [];
    }

    /** @return array<string, mixed> */
    public function authorise(string $idOrNumber): array
    {
        return $this->transitionTo($idOrNumber, InvoiceTransitions::AUTHORISED);
    }

    /**
     * Void an approved invoice.
     *
     * Preflights by default: VOIDED is only legal from AUTHORISED and only
     * while no payment is applied, and Xero's error for the payment case is
     * opaque.
     *
     * @return array<string, mixed>
     */
    public function void(string $idOrNumber, bool $preflight = true): array
    {
        if ($preflight) {
            $current = $this->find($idOrNumber);

            if ($current !== null) {
                $status = (string) ($current['Status'] ?? '');

                if (! InvoiceTransitions::isVoidable($status)) {
                    throw InvalidInvoiceTransitionException::make(
                        $idOrNumber, $status, InvoiceTransitions::VOIDED
                    );
                }

                $paymentIds = array_values(array_filter(array_map(
                    static fn ($payment) => is_array($payment) ? ($payment['PaymentID'] ?? null) : null,
                    (array) ($current['Payments'] ?? []),
                )));

                if ($paymentIds !== []) {
                    throw InvoiceCannotBeVoidedException::hasPayments($idOrNumber, $paymentIds);
                }
            }
        }

        return $this->postStatus($idOrNumber, InvoiceTransitions::VOIDED);
    }

    /**
     * Delete an invoice.
     *
     * Legal from DRAFT *and* SUBMITTED -- not drafts alone. There is no HTTP
     * DELETE for invoices; this is a status change.
     *
     * @return array<string, mixed>
     */
    public function delete(string $idOrNumber, bool $preflight = true): array
    {
        return $this->transitionTo($idOrNumber, InvoiceTransitions::DELETED, $preflight);
    }

    /**
     * Ask Xero to email the invoice.
     *
     * Requires Type ACCREC and status SUBMITTED, AUTHORISED or PAID. Returns
     * 204 No Content. Subject and body come from the organisation's own
     * template and cannot be set through the API.
     */
    public function email(string $idOrNumber): void
    {
        $this->assertUsableIdentifier($idOrNumber);

        $this->client->post($this->endpoint().'/'.$this->pathSegment($idOrNumber).'/Email');
    }

    /** The rendered PDF as raw bytes. */
    public function pdf(string $idOrNumber): string
    {
        $this->assertUsableIdentifier($idOrNumber);

        // Passed as the dedicated $accept argument, not as a header: the
        // client overwrites Accept on purpose so a stray caller header can
        // never make Xero answer with XML.
        $response = $this->client->send(
            'GET',
            $this->endpoint().'/'.$this->pathSegment($idOrNumber),
            [],
            [],
            [],
            'application/pdf',
        );

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($contentType, 'application/pdf')) {
            throw new XeroBridgeException(
                "Asked Xero for a PDF but it returned [{$contentType}]. Nothing will fail until "
                .'someone opens the file, so this is refused here.'
            );
        }

        return $response->body();
    }

    /** The public "view online" link for the invoice. */
    public function onlineUrl(string $idOrNumber): string
    {
        $this->assertUsableIdentifier($idOrNumber);

        $body = $this->client->get(
            $this->endpoint().'/'.$this->pathSegment($idOrNumber).'/OnlineInvoice'
        );

        $url = $body['OnlineInvoices'][0]['OnlineInvoiceUrl'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new XeroBridgeException(
                "Xero returned no online invoice URL for [{$idOrNumber}]. Online invoices are "
                .'only available for approved ACCREC invoices.'
            );
        }

        return $url;
    }

    /** Mark an AUTHORISED invoice as sent, without Xero emailing it. */
    public function markAsSent(string $idOrNumber): array
    {
        $this->assertUsableIdentifier($idOrNumber);

        return $this->unwrapFirst($this->client->post(
            $this->endpoint().'/'.$this->pathSegment($idOrNumber),
            ['Invoices' => [['SentToContact' => true]]],
        )) ?? [];
    }

    /**
     * Apply connection defaults and run the safety guards.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function prepare(array $invoice): array
    {
        if (! isset($invoice['Type']) || $invoice['Type'] === '') {
            throw InvalidInvoicePayloadException::missingType();
        }

        $invoice = $this->guardContact($invoice);

        return $this->applyDefaults($invoice);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function guardContact(array $invoice): array
    {
        $contact = $invoice['Contact'] ?? null;

        if (! is_array($contact) || ! isset($contact['ContactID'])) {
            return $invoice;
        }

        $extra = array_values(array_diff(array_keys($contact), ['ContactID']));

        if ($extra !== [] && ! $this->allowContactMutation) {
            throw UnsafeContactPayloadException::make($extra);
        }

        return $invoice;
    }

    /**
     * Fill in only what the caller left out.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function applyDefaults(array $invoice): array
    {
        $accountCode = $this->defaults->accountCode();
        $taxType = $this->defaults->taxType();

        if (isset($invoice['LineItems']) && is_array($invoice['LineItems'])) {
            foreach ($invoice['LineItems'] as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                if ($accountCode !== null && ! $this->hasKey($line, 'AccountCode')) {
                    $line['AccountCode'] = $accountCode;
                }

                // Never inject a tax type when the line already expresses tax
                // in ANY form. Malaysian SST is per line -- training 8%,
                // education and rental 6% -- so a connection-wide default
                // would stamp the wrong rate on a venue recharge.
                if ($taxType !== null
                    && ! $this->hasKey($line, 'TaxType')
                    && ! $this->hasKey($line, 'TaxAmount')
                ) {
                    $line['TaxType'] = $taxType;
                }

                $invoice['LineItems'][$index] = $line;
            }
        }

        if (($currency = $this->defaults->currency()) !== null && ! $this->hasKey($invoice, 'CurrencyCode')) {
            $invoice['CurrencyCode'] = $currency;
        }

        if (($theme = $this->defaults->brandingThemeId()) !== null && ! $this->hasKey($invoice, 'BrandingThemeID')) {
            $invoice['BrandingThemeID'] = $theme;
        }

        return $invoice;
    }

    /**
     * Treat null and '' as absent, but '0' as present -- '0' is a legitimate
     * account code. Case-insensitive, so a caller writing 'accountCode' is
     * not silently given a second, conflicting key.
     *
     * @param  array<string, mixed>  $array
     */
    private function hasKey(array $array, string $key): bool
    {
        foreach ($array as $existing => $value) {
            if (strcasecmp((string) $existing, $key) === 0) {
                return $value !== null && $value !== '';
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function transitionTo(string $idOrNumber, string $status, bool $preflight = true): array
    {
        if ($preflight) {
            $current = $this->find($idOrNumber);

            if ($current !== null) {
                $from = (string) ($current['Status'] ?? '');

                if (! InvoiceTransitions::allows($from, $status)) {
                    throw InvalidInvoiceTransitionException::make($idOrNumber, $from, $status);
                }
            }
        }

        return $this->postStatus($idOrNumber, $status);
    }

    /** @return array<string, mixed> */
    private function postStatus(string $idOrNumber, string $status): array
    {
        $this->assertUsableIdentifier($idOrNumber);

        return $this->unwrapFirst($this->client->post(
            $this->endpoint().'/'.$this->pathSegment($idOrNumber),
            ['Invoices' => [['Status' => $status]]],
        )) ?? [];
    }

    private function assertUsableIdentifier(string $identifier): void
    {
        if ($identifier === '' || str_contains($identifier, '/')) {
            // A %2F in a path segment is rejected by many edge proxies long
            // before it reaches Xero.
            throw new XeroBridgeException(
                "[{$identifier}] cannot be used as an invoice identifier. Use an InvoiceID or an "
                .'InvoiceNumber without a slash; to look up by number, use invoiceNumbers() on a filter.'
            );
        }
    }
}
