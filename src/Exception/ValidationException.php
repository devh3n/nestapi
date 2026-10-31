<?php

declare(strict_types=1);

namespace NestApi\Exception;

/** PARAM_NOT_FOUND or VALIDATION_ERROR: the server rejected the parameters. */
class ValidationException extends ApiException
{
}
