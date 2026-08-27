<?php

namespace App\Services\AI;

use Exception;

class AiProviderException extends Exception
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
