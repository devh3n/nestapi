<?php

declare(strict_types=1);

namespace NestApi\Exception;

/** Any other error code with an HTTP status of 500 or above. */
class ServerException extends ApiException
{
}
