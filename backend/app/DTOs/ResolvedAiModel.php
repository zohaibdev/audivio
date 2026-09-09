<?php

namespace App\DTOs;

use App\Contracts\AiProvider;

/**
 * Which provider to call, and with which model (§53).
 *
 * Previously resolution returned only a provider, and the model was
 * whatever that provider's row happened to be set to. Carrying the model
 * alongside is what lets a simple task be served by a cheap model — and
 * what lets usage logging record the model actually used rather than the
 * one that was configured.
 */
class ResolvedAiModel
{
    public function __construct(
        public readonly AiProvider $provider,
        /** Null means "whatever the provider row is configured with". */
        public readonly ?string $model = null,
        /** Why this model was chosen, for the admin usage view. */
        public readonly ?string $tier = null,
    ) {}
}
