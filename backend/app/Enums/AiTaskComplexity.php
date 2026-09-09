<?php

namespace App\Enums;

/**
 * How much model a task actually needs (§53).
 *
 * The rule this exists for is "use smaller models for simple tasks, and
 * stronger models for complex analysis". Until now that was impossible to
 * follow: every provider always called the one model configured on its
 * `api_providers` row, so classifying a task had nothing to act on.
 *
 * The tiers describe the *task*, and `ai_models.tier` describes what a
 * model is fit for. A task is served by the cheapest model whose tier
 * meets or exceeds it — never by one below it, because saving money on a
 * judgement call that then comes back wrong is not a saving.
 */
enum AiTaskComplexity: string
{
    /** Extraction, classification, short summarisation of given text. */
    case Simple = 'simple';

    /** Interpretation with a handful of interacting signals. */
    case Standard = 'standard';

    /** Open-ended reasoning, competitive judgement, prioritisation. */
    case Complex = 'complex';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple',
            self::Standard => 'Standard',
            self::Complex => 'Complex',
        };
    }

    /**
     * Ordering used to decide whether a model is strong enough. Higher
     * means more capable, so a Complex task (2) is refused a Simple
     * model (0) but a Simple task will happily take whatever is cheapest
     * at or above its own rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Simple => 0,
            self::Standard => 1,
            self::Complex => 2,
        };
    }

    public function meets(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
