<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Filters;

use DateTimeInterface;
use InvalidArgumentException;
use Peoplelogy\XeroBridge\Support\XeroDate;

/**
 * Fluent builder for GET /Invoices.
 *
 * THREE ENCODING RULES, all of which fail quietly rather than loudly if you
 * get them wrong:
 *
 *  1. toQuery() returns RAW, UNENCODED values. The HTTP client encodes once.
 *     Pre-encoding produces a double-encoded value -- `==` becoming
 *     `%253D%253D` -- which puts a literal % into Xero's filter parser.
 *  2. Clauses join with the ` AND ` keyword, never `&&`. A literal `&` is a
 *     query-string separator, so `&&` truncates the filter and Xero returns a
 *     SUPERSET of the intended rows. That reads as "the filter did nothing",
 *     not as an error.
 *  3. Booleans render as the strings "true"/"false". PHP's own casting gives
 *     "1", which Xero ignores silently.
 *
 * modifiedSince() is a HEADER, not a query parameter -- as a parameter Xero
 * ignores it and returns the whole unfiltered set.
 */
final class InvoiceFilter
{
    public const TYPE_ACCREC = 'ACCREC';

    public const TYPE_ACCPAY = 'ACCPAY';

    /** Xero clamps silently, so out-of-range values are rejected here. */
    public const MAX_PAGE_SIZE = 1000;

    /** @var list<string> */
    private array $statuses = [];

    /** @var list<string> */
    private array $contactIds = [];

    /** @var list<string> */
    private array $invoiceNumbers = [];

    /** @var list<string> */
    private array $ids = [];

    /** @var list<string> */
    private array $where = [];

    private ?string $type = null;

    private ?string $order = null;

    private ?int $page = null;

    private ?int $pageSize = null;

    private bool $summaryOnly = false;

    private bool $createdByMyApp = false;

    private ?string $searchTerm = null;

    private ?string $modifiedSince = null;

    private ?int $unitdp = null;

    public static function make(): self
    {
        return new self;
    }

    /** @param string|list<string> $statuses */
    public function statuses(string|array $statuses): self
    {
        $this->statuses = array_values(array_unique(array_map(
            strtoupper(...),
            (array) $statuses,
        )));

        return $this;
    }

    /** @param string|list<string> $ids */
    public function contactIds(string|array $ids): self
    {
        $this->contactIds = array_values(array_unique((array) $ids));

        return $this;
    }

    /** @param string|list<string> $numbers */
    public function invoiceNumbers(string|array $numbers): self
    {
        $this->invoiceNumbers = array_values(array_unique((array) $numbers));

        return $this;
    }

    /**
     * Prefer this over a `where` with `or`: `or` is only optimised for
     * InvoiceId, so an IDs list is dramatically faster.
     *
     * @param  string|list<string>  $ids
     */
    public function ids(string|array $ids): self
    {
        $this->ids = array_values(array_unique((array) $ids));

        return $this;
    }

    public function type(string $type): self
    {
        $type = strtoupper($type);

        if (! in_array($type, [self::TYPE_ACCREC, self::TYPE_ACCPAY], true)) {
            throw new InvalidArgumentException(
                "Invoice type must be ACCREC or ACCPAY, got [{$type}]."
            );
        }

        $this->type = $type;

        return $this;
    }

    public function reference(string $reference): self
    {
        return $this->whereEquals('Reference', $reference);
    }

    public function contactName(string $name): self
    {
        return $this->whereEquals('Contact.Name', $name);
    }

    /**
     * A date range using Xero's DateTime(y, m, d) literal.
     *
     * Rejects UpdatedDateUTC on purpose: Xero recommends If-Modified-Since
     * for that, and a `where` on it is markedly slower.
     */
    public function dateBetween(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $field = 'Date',
    ): self {
        if (strcasecmp($field, 'UpdatedDateUTC') === 0) {
            throw new InvalidArgumentException(
                'Filter on UpdatedDateUTC with modifiedSince(), which Xero recommends '
                .'and which is far more efficient than a where clause.'
            );
        }

        $this->where[] = sprintf('%s>=%s', $field, XeroDate::toFilterLiteral($from));
        $this->where[] = sprintf('%s<=%s', $field, XeroDate::toFilterLiteral($to));

        return $this;
    }

    /** An escape hatch for clauses the builder does not model. */
    public function whereRaw(string $clause): self
    {
        $this->where[] = $clause;

        return $this;
    }

    public function whereEquals(string $field, string $value): self
    {
        if (str_contains($value, '"')) {
            throw new InvalidArgumentException(
                "A filter value cannot contain a double quote; Xero has no escape for it. Got [{$value}]."
            );
        }

        $this->where[] = sprintf('%s=="%s"', $field, $value);

        return $this;
    }

    public function whereGuid(string $field, string $guid): self
    {
        $this->where[] = sprintf('%s=guid("%s")', $field, $guid);

        return $this;
    }

    /**
     * Sent as the If-Modified-Since HEADER. Format is UTC to the second with
     * no trailing Z.
     */
    public function modifiedSince(DateTimeInterface|string $since): self
    {
        $this->modifiedSince = XeroDate::toModifiedSinceHeader($since);

        return $this;
    }

    /** Optimised order fields are InvoiceId, UpdatedDateUTC and Date. */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);

        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Order direction must be ASC or DESC, got [{$direction}].");
        }

        $this->order = $direction === 'ASC' ? $field : "{$field} {$direction}";

        return $this;
    }

    public function page(int $page, ?int $pageSize = null): self
    {
        if ($page < 1) {
            throw new InvalidArgumentException("Page must be 1 or greater, got [{$page}].");
        }

        $this->page = $page;

        return $pageSize === null ? $this : $this->pageSize($pageSize);
    }

    public function pageSize(int $pageSize): self
    {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            // Xero clamps out-of-range values silently, which makes a typo'd
            // 5000 look like it worked.
            throw new InvalidArgumentException(
                'Page size must be between 1 and '.self::MAX_PAGE_SIZE.", got [{$pageSize}]."
            );
        }

        $this->pageSize = $pageSize;

        return $this;
    }

    /**
     * Excludes exactly Payments, HasAttachments, LineItems and CISDeduction,
     * and forces pagination on.
     */
    public function summaryOnly(bool $summaryOnly = true): self
    {
        $this->summaryOnly = $summaryOnly;

        return $this;
    }

    public function createdByMyApp(bool $only = true): self
    {
        $this->createdByMyApp = $only;

        return $this;
    }

    /** Case-insensitive substring search over InvoiceNumber and Reference. */
    public function search(string $term): self
    {
        $this->searchTerm = $term;

        return $this;
    }

    /** Ask for 4 decimal places on unit amounts instead of 2. */
    public function unitdp(int $places = 4): self
    {
        $this->unitdp = $places;

        return $this;
    }

    public function currentPage(): ?int
    {
        return $this->page;
    }

    public function currentPageSize(): ?int
    {
        return $this->pageSize;
    }

    /** A copy positioned on a specific page, used by the lazy pager. */
    public function forPage(int $page, ?int $pageSize = null): self
    {
        $clone = clone $this;
        $clone->page = $page;

        if ($pageSize !== null) {
            $clone->pageSize = $pageSize;
        }

        return $clone;
    }

    /**
     * RAW values -- the HTTP client encodes them exactly once.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->statuses !== []) {
            $query['Statuses'] = implode(',', $this->statuses);
        }

        if ($this->contactIds !== []) {
            $query['ContactIDs'] = implode(',', $this->contactIds);
        }

        if ($this->invoiceNumbers !== []) {
            $query['InvoiceNumbers'] = implode(',', $this->invoiceNumbers);
        }

        if ($this->ids !== []) {
            $query['IDs'] = implode(',', $this->ids);
        }

        $where = $this->where;

        if ($this->type !== null) {
            $where[] = sprintf('Type=="%s"', $this->type);
        }

        if ($where !== []) {
            // The AND keyword, never '&&'.
            $query['where'] = implode(' AND ', $where);
        }

        if ($this->order !== null) {
            $query['order'] = $this->order;
        }

        if ($this->page !== null) {
            $query['page'] = (string) $this->page;
        }

        if ($this->pageSize !== null) {
            $query['pageSize'] = (string) $this->pageSize;
        }

        if ($this->summaryOnly) {
            // The STRING 'true'. PHP's own cast gives '1', which Xero ignores.
            $query['summaryOnly'] = 'true';
        }

        if ($this->createdByMyApp) {
            $query['createdByMyApp'] = 'true';
        }

        if ($this->searchTerm !== null) {
            $query['SearchTerm'] = $this->searchTerm;
        }

        if ($this->unitdp !== null) {
            $query['unitdp'] = (string) $this->unitdp;
        }

        return $query;
    }

    /** @return array<string, string> */
    public function toHeaders(): array
    {
        return $this->modifiedSince === null
            ? []
            : ['If-Modified-Since' => $this->modifiedSince];
    }
}
