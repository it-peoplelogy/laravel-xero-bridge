<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

it('can reach a non-Accounting Xero API via an absolute url', function () {
    connection();
    Http::fake(['api.xero.com/*' => Http::response(['employees' => []])]);

    XeroBridge::request('GET', 'https://api.xero.com/payroll.xro/2.0/Employees');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.xero.com/payroll.xro/2.0/Employees'
        && $r->hasHeader('Xero-tenant-id', 'tenant-1')
        && $r->hasHeader('Authorization', 'Bearer access-token-1'));
});

it('still prefixes the accounting base url for a relative path', function () {
    connection();
    Http::fake(['api.xero.com/*' => Http::response(['CreditNotes' => []])]);

    XeroBridge::request('GET', 'CreditNotes');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.xero.com/api.xro/2.0/CreditNotes');
});
