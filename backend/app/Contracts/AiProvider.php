<?php

namespace App\Contracts;

use App\DTOs\AiResponse;

interface AiProvider
{
    /**
     * Send a structured prompt to the underlying AI provider and return a
     * normalized response. Implementations must not leak provider-specific
     * response shapes past this boundary.
     *
     * @param  array<string, mixed>  $context  Pre-extracted, minimal context — never raw full-page HTML.
     */
    public function analyze(string $prompt, array $context = []): AiResponse;

    public function name(): string;
}
