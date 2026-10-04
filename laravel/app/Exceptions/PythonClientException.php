<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A non-2xx response from the python service (ADR 0061). Connection-level
 * failures surface as Illuminate's ConnectionException instead — they are
 * retried by PythonClient before this exception can ever be built.
 */
class PythonClientException extends RuntimeException
{
    public function __construct(string $endpointLabel, int $status, string $body)
    {
        parent::__construct("Python {$endpointLabel} service error: {$status} - {$body}");
    }
}
