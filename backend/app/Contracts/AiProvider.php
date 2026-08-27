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

    /**
     * Sends one real, minimal request to prove the configured credentials
     * actually work — used by the admin "Test Connection" action. Unlike
     * analyze(), this only requires the provider to be *configured*, not
     * enabled, so an admin can validate a key before flipping it on.
     *
     * @return array{success: bool, response_time_ms: ?int, message: string}
     */
    public function testConnection(): array;
}
