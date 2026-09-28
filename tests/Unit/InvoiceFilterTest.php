<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

it('builds a status and date filter', function () {
    $filter = InvoiceFilter::make()
        ->statuses(['authorised', 'PAID'])
        ->type('ACCREC')
        ->dateBetween('2026-01-01', '2026-03-31')
        ->orderBy('Date', 'DESC')
        ->page(2, 50);

    expect($filter->toQuery())->toBe([
        'Statuses' => 'AUTHORISED,PAID',
        // Xero's DateTime(y, m, d) literal, joined with the AND keyword.
        'where' => 'Date>=DateTime(2026, 1, 1) AND Date<=DateTime(2026, 3, 31) AND Type=="ACCREC"',
        'order' => 'Date DESC',
        'page' => '2',
        'pageSize' => '50',
    ]);
});

it('builds an ids and reference filter', function () {
    $filter = InvoiceFilter::make()
        ->ids(['a-1', 'a-2'])
        ->reference('CCF-001')
        ->summaryOnly();

    expect($filter->toQuery())->toBe([
        'IDs' => 'a-1,a-2',
        'where' => 'Reference=="CCF-001"',
        // The STRING 'true'. PHP's cast would give '1', which Xero ignores.
        'summaryOnly' => 'true',
    ]);
});

it('builds a contact filter', function () {
    $filter = InvoiceFilter::make()
        ->contactIds('c-1')
        ->invoiceNumbers(['INV-01', 'INV-02'])
        ->search('training');

    expect($filter->toQuery())->toBe([
        'ContactIDs' => 'c-1',
        'InvoiceNumbers' => 'INV-01,INV-02',
        'SearchTerm' => 'training',
    ]);
});

it('never joins clauses with an ampersand', function () {
    // A literal & is a query-string separator, so '&&' truncates the filter
    // and Xero silently returns a SUPERSET of the intended rows.
    $query = InvoiceFilter::make()
        ->reference('A')
        ->contactName('B')
        ->toQuery();

    expect($query['where'])->toBe('Reference=="A" AND Contact.Name=="B"')
        ->and($query['where'])->not->toContain('&');
});

it('leaves values unencoded for the client to encode once', function () {
    // Pre-encoding here would double-encode, putting a literal % into Xero's
    // filter parser.
    $query = InvoiceFilter::make()->reference('CCF-001')->toQuery();

    expect($query['where'])->toBe('Reference=="CCF-001"')
        ->and($query['where'])->not->toContain('%22');
});

it('sends modifiedSince as a header, never as a query parameter', function () {
    $filter = InvoiceFilter::make()
        ->modifiedSince(CarbonImmutable::parse('2026-09-01 08:30:00', 'Asia/Kuala_Lumpur'));

    // UTC, to the second, with no trailing Z.
    expect($filter->toHeaders())->toBe(['If-Modified-Since' => '2026-09-01T00:30:00'])
        // As a query parameter Xero ignores it and returns everything, which
        // reads as "nothing was filtered out".
        ->and($filter->toQuery())->not->toHaveKey('If-Modified-Since');
});

it('rejects a value containing a double quote', function () {
    // Xero has no escape for it.
    InvoiceFilter::make()->reference('he said "hi"');
})->throws(InvalidArgumentException::class, 'double quote');

it('rejects a page size outside 1..1000 rather than letting Xero clamp it', function (int $size) {
    InvoiceFilter::make()->pageSize($size);
})->with([0, -1, 1001, 5000])->throws(InvalidArgumentException::class);

it('rejects an invalid invoice type', function () {
    InvoiceFilter::make()->type('SOMETHING');
})->throws(InvalidArgumentException::class, 'ACCREC or ACCPAY');

it('points UpdatedDateUTC filters at modifiedSince', function () {
    InvoiceFilter::make()->dateBetween('2026-01-01', '2026-03-31', 'UpdatedDateUTC');
})->throws(InvalidArgumentException::class, 'modifiedSince');

it('omits everything that was never set', function () {
    expect(InvoiceFilter::make()->toQuery())->toBe([])
        ->and(InvoiceFilter::make()->toHeaders())->toBe([]);
});

it('carries the page forward without mutating the original', function () {
    $filter = InvoiceFilter::make()->statuses('DRAFT');
    $paged = $filter->forPage(3, 25);

    expect($paged->toQuery()['page'])->toBe('3')
        ->and($paged->toQuery()['pageSize'])->toBe('25')
        ->and($filter->toQuery())->not->toHaveKey('page');
});
