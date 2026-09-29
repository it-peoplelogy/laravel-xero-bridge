<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Resources;

use Illuminate\Database\Eloquent\Model;
use Peoplelogy\XeroBridge\Client\XeroHttpClient;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Support\ConnectionDefaults;
use Peoplelogy\XeroBridge\Writes\WriteClaim;
use Peoplelogy\XeroBridge\Writes\XeroWriteRecorder;
use Throwable;

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

    /**
     * The consuming application's record this write belongs to, if any.
     *
     * Scoped to the NEXT write only -- like withContactMutation() and
     * replacingLineItems(), it resets itself after one call. A lingering owner
     * would silently attach the wrong record to the next invoice.
     */
    private ?Model $owner = null;

    private ?string $reference = null;

    /** The path segment, e.g. 'Invoices'. */
    abstract protected function endpoint(): string;

    /**
     * Name the record this write is for, switching on duplicate protection.
     *
     *     XeroBridge::invoices()->for($order)->create([...]);
     *
     * With the write ledger enabled and migrated, a second attempt for the
     * same record throws XeroWriteAlreadyClaimedException carrying the id
     * already created -- which is the "make the job a no-op once it is set"
     * the documentation has always asked consumers to implement by hand.
     *
     * Without an owner nothing can be deduplicated, because there is nothing
     * to deduplicate against.
     */
    public function for(Model $owner, ?string $reference = null): static
    {
        $clone = clone $this;
        $clone->owner = $owner;
        $clone->reference = $reference;

        return $clone;
    }

    /**
     * Distinguish two legitimate writes for the SAME record.
     *
     * An order can need a deposit invoice and a final invoice; without this
     * the second would be refused as a duplicate of the first.
     */
    public function reference(string $reference): static
    {
        $clone = clone $this;
        $clone->reference = $reference;

        return $clone;
    }

    /**
     * Claim the right to write, throwing if it has already been written.
     *
     * Resolved from the container rather than injected, so no constructor
     * signature changes and a consumer constructing a resource by hand keeps
     * working.
     */
    protected function claimWrite(string $operation, ?string $idempotencyKey = null): WriteClaim
    {
        return app(XeroWriteRecorder::class)->claim(
            $this->connectionKey,
            $operation,
            $this->owner,
            $this->reference,
            $idempotencyKey,
        );
    }

    /** @param array<string, mixed> $resource */
    protected function confirmWrite(WriteClaim $claim, array $resource): void
    {
        app(XeroWriteRecorder::class)->succeed($claim, $resource);
    }

    /**
     * Release a claim ONLY where non-creation is proven.
     *
     * A XeroValidationException is Xero rejecting the payload outright, so
     * nothing was created and the slot is free for a corrected retry. Anything
     * else -- a timeout, a 5xx, a rate limit -- proves nothing and must leave
     * the claim standing, because the write may have landed.
     */
    protected function releaseWriteOnProvenFailure(WriteClaim $claim, Throwable $e): void
    {
        if ($e instanceof XeroValidationException) {
            app(XeroWriteRecorder::class)->releaseProvenFailure($claim);
        }
    }

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
