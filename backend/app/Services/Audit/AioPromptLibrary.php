<?php

namespace App\Services\Audit;

use App\Models\Website;
use App\Models\WebsiteAioPrompt;
use App\Services\Plan\EntitlementResolver;

/**
 * Which AIO prompts an audit runs (§42): the ten defaults from config,
 * plus whatever custom prompts the website's owner has defined, bounded
 * by the plan's AI budget.
 *
 * Two separate limits apply and they mean different things:
 *
 * - `custom_aio_prompt_limit` caps how many custom prompts a website may
 *   *define*. Enforced when saving one, so a user is told at the point
 *   they add it rather than silently having it ignored later.
 * - `aio_prompt_limit` caps how many prompts one audit may *execute*.
 *   That is cost control, and it truncates the combined list here.
 *
 * Custom prompts are appended after the defaults and truncated last, so a
 * tight execution budget removes the owner's own questions before the
 * baseline checks — the baseline is what makes one audit comparable to
 * the next.
 */
class AioPromptLibrary
{
    public function __construct(
        private readonly AioContextBuilder $context,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * @return array<int, array{key: string, question: string, source: string}>
     */
    public function for(Website $website): array
    {
        $hasContext = $this->context->isSufficient($website);

        $defaults = collect(config('aio_prompts.defaults'))
            // A context-dependent prompt with no context asks the model to
            // grade the site against a goal it would have to invent. Skipped
            // rather than asked blindly; the report says which and why.
            ->reject(fn (array $p) => $p['needs_context'] && ! $hasContext)
            ->map(fn (array $p) => [
                'key' => $p['key'],
                'question' => $p['question'],
                'source' => 'default',
            ]);

        $custom = $website->aioPrompts()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (WebsiteAioPrompt $p) => [
                'key' => "custom_{$p->id}",
                'question' => $p->prompt,
                'source' => 'custom',
            ]);

        $all = $defaults->concat($custom)->values();

        $executionLimit = $this->entitlements->forOrDeny($website->user)->aioPromptLimit;

        return $executionLimit === null
            ? $all->all()
            : $all->take(max(0, $executionLimit))->all();
    }

    /**
     * How many custom prompts this website's owner may define in total.
     * Null means unlimited.
     */
    public function customPromptLimit(Website $website): ?int
    {
        return $this->entitlements->forOrDeny($website->user)->customAioPromptLimit;
    }

    /**
     * Whether one more custom prompt may be added.
     */
    public function canAddCustomPrompt(Website $website): bool
    {
        $limit = $this->customPromptLimit($website);

        if ($limit === null) {
            return true;
        }

        return $website->aioPrompts()->count() < $limit;
    }

    /**
     * Which context-dependent checks were skipped, and what would unlock
     * them — so a report can say "3 checks need your business profile"
     * rather than quietly running eight prompts instead of ten.
     *
     * @return array{skipped: string[], missing_fields: string[]}
     */
    public function skippedFor(Website $website): array
    {
        if ($this->context->isSufficient($website)) {
            return ['skipped' => [], 'missing_fields' => []];
        }

        return [
            'skipped' => collect(config('aio_prompts.defaults'))
                ->filter(fn (array $p) => $p['needs_context'])
                ->pluck('key')
                ->values()
                ->all(),
            'missing_fields' => $this->context->missingFields($website),
        ];
    }
}
