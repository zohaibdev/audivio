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
     * @param  string|null  $model  Overrides the provider's configured model for this one
     *                              request, so a simple task can be served by a cheaper
     *                              model (§53). Null keeps the configured one.
     */
    public function analyze(string $prompt, array $context = [], ?string $model = null): AiResponse;

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
