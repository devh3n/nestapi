<?php

declare(strict_types=1);

namespace NestApi\Exception;

/** RATE_LIMITED or RATE_LIMIT_EXCEEDED. */
class RateLimitException extends ApiException
{
}
