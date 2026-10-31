<?php

declare(strict_types=1);

namespace NestApi\Exception;

use RuntimeException;
use Throwable;

/**
 * The server answered with `success: false`. Branch on $errorCode, not on the
 * message, which may change. Unrecognized codes land here directly.
 */
class ApiException extends RuntimeException
{
    /**
     * @param string $errorCode Value of `error.code`; empty if the server sent none.
     * @param int    $status    HTTP status code.
     * @param mixed  $details   Value of `error.details`: a list for PARAM_NOT_FOUND,
     *                          a map like `query.age` for VALIDATION_ERROR.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly mixed $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
