<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Resources;

use Peoplelogy\XeroBridge\Client\XeroHttpClient;
use Peoplelogy\XeroBridge\Support\ConnectionDefaults;

/**
 * Shared base for the endpoint wrappers.
 */
abstract class Resource
{
    public function __construct(
        protected readonly XeroHttpClient $client,
        protected readonly ConnectionDefaults $defaults,
        protected readonly string $connectionKey,
    ) {}

    /** The path segment, e.g. 'Invoices'. */
    abstract protected function endpoint(): string;

    /** The key Xero wraps the collection in -- usually the same word. */
    protected function wrapper(): string
    {
        return $this->endpoint();
    }

    /**
     * Pull the collection out of a Xero response body.
     *
     * @param  array<mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    protected function unwrap(array $body): array
    {
        $items = $body[$this->wrapper()] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * The first item of a collection response, or null.
     *
     * @param  array<mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function unwrapFirst(array $body): ?array
    {
        $items = $this->unwrap($body);

        return $items === [] ? null : $items[0];
    }

    /**
     * Xero accepts either a GUID or a human identifier such as INV-01514 in
     * the path, but a value containing a slash would be rejected by edge
     * proxies long before it reached Xero.
     */
    protected function pathSegment(string $identifier): string
    {
        return rawurlencode($identifier);
    }
}
