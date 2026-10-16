<?php

namespace App\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use Illuminate\Support\Collection;

/**
 * The countable half of the conversion audit (§46).
 *
 * The nine CRO areas and eight ecommerce areas the product reports on are
 * a mix of judgement and fact. "Is this value proposition compelling" is
 * judgement, and the AI is there for it. "Does this form ask for eleven
 * things before telling you the price", "is there a returns policy
 * anywhere on this site", "does the main button say Submit" — those are
 * facts, and answering them here means they are free, repeatable, and
 * still answered when the AI provider is down.
 *
 * Everything this returns is derived from crawl data. Nothing is
 * inferred about pages that were not crawled: a returns policy on page 40
 * of a 25-page crawl is reported as "not found on the pages we looked
 * at", which the report states plainly.
 */
class ConversionSignalDetector
{
    /**
     * @return array<string, mixed>
     */
    public function detect(Audit $audit): array
    {
        $pages = $audit->pages;

        if ($pages->isEmpty()) {
            return ['pages_analyzed' => 0];
        }

        $corpus = $this->textCorpus($pages);

        return [
            'pages_analyzed' => $pages->count(),
            'ctas' => $this->ctaSignals($pages),
            'forms' => $this->formSignals($pages),
            'navigation' => $this->navigationSignals($pages),
            'trust' => $this->trustSignals($pages, $corpus),
            'social_proof' => $this->socialProofSignals($pages, $corpus),
            'policies' => $this->policyPages($pages),
            'value_proposition' => $this->valuePropositionSignals($pages),
            'mobile' => $this->mobileSignals($pages),
            'checkout' => $this->checkoutSignals($pages, $corpus),
        ];
    }

    /**
     * CTA visibility (is there one at all) and clarity (does it say what
     * happens next).
     *
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function ctaSignals(Collection $pages): array
    {
        $vague = config('cro_signals.vague_cta_text');
        $pagesWithout = [];
        $vagueCtas = [];
        $total = 0;

        foreach ($pages as $page) {
            $ctas = $page->cta_elements ?? [];
            $total += count($ctas);

            if ($ctas === []) {
                $pagesWithout[] = $page->url;

                continue;
            }

            foreach ($ctas as $cta) {
                $text = trim((string) ($cta['text'] ?? ''));

                // Exact match, not substring: "Learn more about pricing"
                // is specific, "Learn more" on its own is not.
                if ($text !== '' && in_array(strtolower($text), $vague, true)) {
                    $vagueCtas[] = ['url' => $page->url, 'text' => $text];
                }
            }
        }

        return [
            'total' => $total,
            'pages_without_cta' => $pagesWithout,
            'vague_ctas' => array_slice($vagueCtas, 0, 10),
            'vague_cta_count' => count($vagueCtas),
        ];
    }

    /**
     * Form friction: how much a visitor is asked for before they get
     * anything.
     *
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function formSignals(Collection $pages): array
    {
        $max = (int) config('cro_signals.thresholds.max_form_fields');
        $long = [];
        $formCount = 0;

        foreach ($pages as $page) {
            foreach ($page->forms ?? [] as $form) {
                $fields = $form['fields'] ?? [];

                // Hidden inputs are plumbing (CSRF tokens, tracking) and
                // cost a visitor nothing, so they are not friction.
                $visible = array_filter(
                    $fields,
                    fn ($field) => strtolower((string) ($field['type'] ?? '')) !== 'hidden'
                );

                $formCount++;

                if (count($visible) > $max) {
                    $long[] = [
                        'url' => $page->url,
                        'field_count' => count($visible),
                        'required_count' => count(array_filter($visible, fn ($f) => ! empty($f['required']))),
                    ];
                }
            }
        }

        return [
            'form_count' => $formCount,
            'long_forms' => array_slice($long, 0, 10),
            'long_form_count' => count($long),
            'max_fields' => $max,
        ];
    }

    /**
     * Navigation breadth, measured from the links the homepage actually
     * shows.
     *
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function navigationSignals(Collection $pages): array
    {
        $entry = $pages->first();

        if ($entry === null) {
            return ['primary_links' => null];
        }

        $host = parse_url($entry->url, PHP_URL_HOST);

        // Distinct internal destinations, not raw link count: a nav that
        // repeats the same link in a header and a footer is one route, and
        // counting it twice would report tidy navigation as sprawl.
        $destinations = collect($entry->links ?? [])
            ->map(fn ($link) => is_string($link['href'] ?? null) ? $link['href'] : null)
            ->filter()
            ->filter(fn (string $href) => str_contains($href, (string) $host))
            ->map(fn (string $href) => rtrim(parse_url($href, PHP_URL_PATH) ?? '/', '/'))
            ->unique()
            ->values();

        return [
            'primary_links' => $destinations->count(),
            'max' => (int) config('cro_signals.thresholds.max_primary_nav_links'),
            'min' => (int) config('cro_signals.thresholds.min_primary_nav_links'),
        ];
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function trustSignals(Collection $pages, string $corpus): array
    {
        $found = array_values(array_filter(
            config('cro_signals.trust_phrases'),
            fn (string $phrase) => str_contains($corpus, $phrase),
        ));

        return [
            'phrases_found' => $found,
            'has_https' => str_starts_with(strtolower((string) $pages->first()?->url), 'https://'),
            'has_contact_route' => $this->hasContactRoute($pages),
        ];
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function socialProofSignals(Collection $pages, string $corpus): array
    {
        $phrases = array_values(array_filter(
            config('cro_signals.social_proof_phrases'),
            fn (string $phrase) => str_contains($corpus, $phrase),
        ));

        // Review markup is the strongest form of this: it is a claim the
        // site has published in a machine-readable, checkable way.
        $hasReviewSchema = $pages->contains(function (AuditPage $page) {
            $json = json_encode($page->structured_data ?? []);

            return is_string($json)
                && (str_contains($json, '"AggregateRating"') || str_contains($json, '"Review"'));
        });

        return [
            'phrases_found' => $phrases,
            'has_review_schema' => $hasReviewSchema,
        ];
    }

    /**
     * Which policy/support pages exist among the crawled pages.
     *
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function policyPages(Collection $pages): array
    {
        $definitions = config('cro_signals.policy_pages');
        $result = [];

        foreach ($definitions as $key => $definition) {
            $result[$key] = [
                'label' => $definition['label'],
                'found' => $this->matchesRoute($pages, $definition['patterns']),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function valuePropositionSignals(Collection $pages): array
    {
        $entry = $pages->first();
        $h1 = collect($entry?->headings ?? [])->firstWhere('level', 1);

        return [
            'headline' => $h1['text'] ?? null,
            'headline_length' => isset($h1['text']) ? mb_strlen((string) $h1['text']) : null,
            'has_meta_description' => ! empty($entry?->meta_description),
        ];
    }

    /**
     * Mobile conversion signals, from what the crawl can actually see.
     *
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function mobileSignals(Collection $pages): array
    {
        $entry = $pages->first();

        return [
            // A tap-to-call link is the single highest-intent mobile
            // action a service or local business can offer.
            'has_tel_link' => $this->hasScheme($pages, 'tel:'),
            'has_mailto_link' => $this->hasScheme($pages, 'mailto:'),
            // Captured by the accessibility scan rather than re-derived:
            // axe already checks whether targets are big enough to hit.
            'has_target_size_issues' => collect($entry?->accessibility_violations ?? [])
                ->contains(fn ($v) => in_array($v['id'] ?? '', ['target-size'], true)),
        ];
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, mixed>
     */
    private function checkoutSignals(Collection $pages, string $corpus): array
    {
        $found = array_values(array_filter(
            config('cro_signals.checkout_phrases'),
            fn (string $phrase) => str_contains($corpus, $phrase),
        ));

        return [
            'phrases_found' => $found,
            'has_cart_route' => $this->matchesRoute($pages, ['cart', 'basket', 'checkout', 'bag']),
        ];
    }

    /**
     * Whether any crawled page's URL — or any link's text — matches one of
     * these patterns.
     *
     * Both are checked because a site can link a returns policy from its
     * footer with a URL that says nothing (`/p/17`), and can equally have
     * a `/returns` URL nothing links to.
     *
     * @param  Collection<int, AuditPage>  $pages
     * @param  string[]  $patterns
     */
    private function matchesRoute(Collection $pages, array $patterns): bool
    {
        foreach ($pages as $page) {
            $path = strtolower((string) parse_url($page->url, PHP_URL_PATH));

            foreach ($patterns as $pattern) {
                if (str_contains($path, $pattern)) {
                    return true;
                }
            }

            foreach ($page->links ?? [] as $link) {
                $href = strtolower((string) ($link['href'] ?? ''));
                $text = strtolower(trim((string) ($link['text'] ?? '')));

                foreach ($patterns as $pattern) {
                    if (str_contains($href, $pattern) || str_contains($text, $pattern)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     */
    private function hasContactRoute(Collection $pages): bool
    {
        return $this->matchesRoute($pages, ['contact'])
            || $this->hasScheme($pages, 'tel:')
            || $this->hasScheme($pages, 'mailto:');
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     */
    private function hasScheme(Collection $pages, string $scheme): bool
    {
        foreach ($pages as $page) {
            foreach ($page->links ?? [] as $link) {
                if (str_starts_with(strtolower((string) ($link['href'] ?? '')), $scheme)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     */
    private function textCorpus(Collection $pages): string
    {
        $parts = [];

        foreach ($pages as $page) {
            $parts[] = $page->title;
            $parts[] = $page->meta_description;

            foreach (array_slice($page->headings ?? [], 0, 30) as $heading) {
                $parts[] = $heading['text'] ?? '';
            }

            foreach (array_slice($page->links ?? [], 0, 60) as $link) {
                $parts[] = $link['text'] ?? '';
            }

            foreach ($page->cta_elements ?? [] as $cta) {
                $parts[] = $cta['text'] ?? '';
            }
        }

        return strtolower(implode(' ', array_filter($parts)));
    }
}
