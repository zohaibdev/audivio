<?php

namespace App\Services\AI;

use Exception;

/**
 * A provider call that did not produce a usable response.
 *
 * `responseTimeMs` carries the elapsed time of the failed call (§56). It
 * was already being measured and then thrown away, so a failed "Test
 * Connection" reported `response_time_ms: null` and the admin
 * average-response-time figure silently excluded exactly the calls most
 * worth knowing the latency of — a provider that is timing out is slow
 * before it is broken.
 */
class AiProviderException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $responseTimeMs = null,
    ) {
        parent::__construct($message);
    }
}
