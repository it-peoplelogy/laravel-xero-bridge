<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Writes;

/**
 * A held claim on one write into Xero.
 *
 * Returned by XeroWriteRecorder::claim() and handed back to succeed() or
 * releaseProvenFailure(), so a Resource never touches the model or a row id
 * directly and the three-step protocol lives in exactly one class.
 *
 * A claim with a null id is a NO-OP claim: recording is off, the table is not
 * there, or the caller named no owner. The resource behaves exactly as it did
 * before this feature existed.
 */
final class WriteClaim
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $operation,
        public readonly ?string $claimKey,
    ) {}

    /** Nothing was claimed, so nothing needs confirming or releasing. */
    public static function none(string $operation): self
    {
        return new self(null, $operation, null);
    }

    public function isHeld(): bool
    {
        return $this->id !== null;
    }
}
