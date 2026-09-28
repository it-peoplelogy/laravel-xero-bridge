<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * Xero rejected the payload (HTTP 400, Type: ValidationException).
 *
 * The useful detail is in validationErrors(); the message carries the first
 * few inline because that is what ends up in a log line.
 */
class XeroValidationException extends XeroBridgeException {}
