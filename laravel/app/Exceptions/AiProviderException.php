<?php

namespace App\Exceptions;

use Exception;

class AiProviderException extends Exception
{
    public function __construct(
        string $userMessage,
        private readonly int $statusCode = 502,
        ?Exception $previous = null,
    ) {
        parent::__construct($userMessage, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
