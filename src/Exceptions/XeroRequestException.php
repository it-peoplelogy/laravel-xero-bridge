<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * Any other non-2xx that does not fit a more specific subclass -- 404, 405,
 * 409, 412 and so on.
 */
class XeroRequestException extends XeroBridgeException {}
