<?php

declare(strict_types=1);

namespace NestApi\Exception;

use RuntimeException;

/**
 * No usable API answer arrived: a network failure, a non-JSON body, or a body
 * without a `success` field. Deliberately not an ApiException.
 */
class TransportException extends RuntimeException
{
}
