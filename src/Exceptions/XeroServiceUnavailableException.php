<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * Xero is temporarily unavailable (502/503/504).
 *
 * 503 has two distinct meanings and a plain-text body rather than JSON:
 * "The Xero API is currently offline for maintenance" and "The Organisation
 * is offline". Xero recommends retrying the latter after roughly 5 minutes,
 * which is why retryAfter() defaults to 300 rather than to something short.
 */
class XeroServiceUnavailableException extends XeroBridgeException {}
