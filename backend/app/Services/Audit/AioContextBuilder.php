<?php

namespace App\Services\Audit;

use App\Models\Website;

/**
 * The business context an AIO prompt is answered against (§42).
 *
 * "Is this page clear to a machine reader" is not answerable in the
 * abstract — clear *about what*, *to whom*? Without the business profile
 * the model has only the page's own words to go on, so it grades the site
 * against whatever it guesses the site is for, and the answer is
 * circular. With the profile it can say the content does not make the
 * stated subject explicit, which is a finding the owner can act on.
 *
 * Only fields the owner actually filled in are included. An absent field
 * is omitted rather than sent as null or an empty string, so the model is
 * never invited to reason about "category: ''".
 */
class AioContextBuilder
{
    /**
     * The onboarding fields that make context-dependent prompts
     * answerable. Listed in the order the spec names them (§42).
     */
    private const CONTEXT_FIELDS = [
        'category' => 'business_category',
        'description' => 'business_description',
        'products_or_services' => 'products_or_services',
        'country' => 'country',
        'aio_goal' => 'aio_goal',
    ];

    /**
     * @return array<string, mixed> omitted entirely when nothing is filled in
     */
    public function build(Website $website): array
    {
        $context = [];

        foreach (self::CONTEXT_FIELDS as $column => $key) {
            $value = $website->{$column};

            if (filled($value)) {
                $context[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        $competitors = $this->competitors($website);

        if ($competitors !== []) {
            $context['competitors'] = $competitors;
        }

        return $context;
    }

    /**
     * Whether enough of the profile is filled in for the context-dependent
     * prompts to be worth asking.
     *
     * The AIO goal is the one that matters: it is what the
     * goal-alignment, offering-coverage and audience prompts are all
     * measuring against. A description without a goal still leaves those
     * prompts guessing.
     */
    public function isSufficient(Website $website): bool
    {
        return filled($website->aio_goal);
    }

    /**
     * Which onboarding fields are still empty — what the UI prompts for,
     * and what the report cites when it explains that some checks were
     * skipped.
     *
     * @return string[]
     */
    public function missingFields(Website $website): array
    {
        $missing = [];

        foreach (array_keys(self::CONTEXT_FIELDS) as $column) {
            if (blank($website->{$column})) {
                $missing[] = $column;
            }
        }

        if ($this->competitors($website) === []) {
            $missing[] = 'competitors';
        }

        return $missing;
    }

    /**
     * The competitor names the model should be aware of: what the owner
     * declared, plus anything discovery has found. Deduplicated, because
     * a discovered competitor the owner also named is one competitor.
     *
     * @return string[]
     */
    private function competitors(Website $website): array
    {
        $declared = collect($website->competitors ?? [])
            ->filter(fn ($c) => is_string($c) && trim($c) !== '')
            ->map(fn ($c) => trim($c));

        $analyzed = $website->relationLoaded('competitorRecords')
            ? $website->competitorRecords
            : $website->competitorRecords()->get();

        return $declared
            ->merge($analyzed->map(fn ($c) => $c->name ?: $c->normalized_domain))
            ->filter()
            ->unique(fn ($c) => mb_strtolower($c))
            ->take(10)
            ->values()
            ->all();
    }
}
