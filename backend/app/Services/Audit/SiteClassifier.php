<?php

namespace App\Services\Audit;

use App\DTOs\SiteClassification;
use App\Enums\PlatformName;
use App\Enums\SiteType;
use App\Models\Audit;
use App\Models\AuditPage;
use Illuminate\Support\Collection;

/**
 * Works out what kind of website this is, from measured signals (§46).
 *
 * Deliberately deterministic and AI-free. This decides which services get
 * reported at all, so it has to be explainable and repeatable: the same
 * crawl must always produce the same classification, and a reader who
 * disagrees has to be able to see exactly what it was based on.
 *
 * Every type accumulates points from independent signals. The winner has
 * to be **clearly ahead** — a site whose top two types are close is
 * reported as `Other`, because a confident wrong label is worse than an
 * honest "we could not tell". `Other` is a real answer here, not a
 * failure state.
 */
class SiteClassifier
{
    /**
     * A type must hold a **strict majority** of the total evidence to be
     * claimed. Exactly 50% is the two-way tie — a site with equal evidence
     * for a shop and for a blog — and that is precisely the case where
     * picking one is guessing.
     */
    private const MIN_CONFIDENCE = 50;

    public function classify(Audit $audit): SiteClassification
    {
        $pages = $audit->pages;

        if ($pages->isEmpty()) {
            return new SiteClassification(
                type: SiteType::Other,
                confidence: 0,
                signals: ['No pages were crawled, so there was nothing to classify.'],
            );
        }

        $scores = [];
        $signals = [];

        foreach ($this->rules($audit, $pages) as [$type, $points, $signal]) {
            if ($points <= 0) {
                continue;
            }

            $scores[$type->value] = ($scores[$type->value] ?? 0) + $points;
            $signals[] = ['weight' => $points, 'text' => $signal];
        }

        if ($scores === []) {
            return new SiteClassification(
                type: SiteType::Other,
                confidence: 0,
                signals: ['None of the signals this audit looks for were found on the crawled pages.'],
            );
        }

        arsort($scores);
        $total = array_sum($scores);
        $winner = array_key_first($scores);
        $confidence = (int) round(($scores[$winner] / $total) * 100);

        // Strongest evidence first — that is the order a reader checks it
        // in when they want to know whether we got this right.
        usort($signals, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);
        $signalText = array_map(fn (array $s) => $s['text'], $signals);

        if ($confidence <= self::MIN_CONFIDENCE) {
            return new SiteClassification(
                type: SiteType::Other,
                confidence: $confidence,
                signals: array_merge(
                    ['The signals pointed at more than one kind of site, so no single type is claimed.'],
                    $signalText,
                ),
                scores: $scores,
            );
        }

        return new SiteClassification(
            type: SiteType::from($winner),
            confidence: $confidence,
            signals: $signalText,
            scores: $scores,
        );
    }

    /**
     * Every signal, as [type, points, explanation].
     *
     * Weights are relative, not absolute: 3 is near-conclusive on its own,
     * 2 is strong corroboration, 1 is suggestive only. Nothing scoring 1
     * can win a classification by itself.
     *
     * @param  Collection<int, AuditPage>  $pages
     * @return array<int, array{0: SiteType, 1: int, 2: string}>
     */
    private function rules(Audit $audit, Collection $pages): array
    {
        $schemaTypes = $this->schemaTypes($pages);
        $urlPaths = $pages->map(fn (AuditPage $p) => strtolower(parse_url($p->url, PHP_URL_PATH) ?? ''))->all();
        $allText = $this->textCorpus($pages);

        return [
            // --- Ecommerce ---
            [SiteType::Ecommerce, in_array($audit->website->platform, [PlatformName::Shopify, PlatformName::WooCommerce], true) ? 3 : 0,
                'Runs on an ecommerce platform ('.($audit->website->platform?->value ?? '').')'],
            [SiteType::Ecommerce, isset($schemaTypes['Product']) ? 3 : 0,
                'Product structured data was found'],
            [SiteType::Ecommerce, $this->anyCta($pages, ['add to cart', 'add to basket', 'add to bag']) ? 3 : 0,
                'An "add to cart" action was found'],
            [SiteType::Ecommerce, $this->pathMatches($urlPaths, '#/(product|products|shop|store|collections)(/|$)#') >= 2 ? 2 : 0,
                'Several crawled pages sit under product or shop URLs'],
            [SiteType::Ecommerce, isset($schemaTypes['Offer']) ? 1 : 0,
                'Offer/pricing structured data was found'],

            // --- SaaS ---
            [SiteType::Saas, isset($schemaTypes['SoftwareApplication']) ? 3 : 0,
                'SoftwareApplication structured data was found'],
            [SiteType::Saas, $this->hasPricingTiers($pages, $urlPaths) ? 2 : 0,
                'A pricing page with recurring plan wording was found'],
            [SiteType::Saas, $this->anyCta($pages, ['start free trial', 'start trial', 'free trial', 'sign up free', 'get started free']) ? 2 : 0,
                'A free-trial or sign-up call to action was found'],
            [SiteType::Saas, $this->pathMatches($urlPaths, '#/(login|signin|sign-in|dashboard|app)(/|$)#') >= 1 ? 1 : 0,
                'The site links to a login or app area'],
            [SiteType::Saas, $this->mentions($allText, ['per month', 'per user', 'monthly plan', 'billed annually', 'api documentation']) ? 1 : 0,
                'Subscription wording (per month, per user, billed annually) appears in the content'],

            // --- Local business ---
            [SiteType::LocalBusiness, isset($schemaTypes['LocalBusiness']) ? 3 : 0,
                'LocalBusiness structured data was found'],
            [SiteType::LocalBusiness, $this->hasPostalAddressSchema($pages) ? 2 : 0,
                'A postal address was published in structured data'],
            [SiteType::LocalBusiness, $this->hasTelLink($pages) ? 1 : 0,
                'A click-to-call telephone link was found'],
            [SiteType::LocalBusiness, $this->mentions($allText, ['opening hours', 'opening times', 'find us', 'visit us', 'our location']) ? 1 : 0,
                'Opening hours or location wording appears in the content'],

            // --- Blog ---
            [SiteType::Blog, isset($schemaTypes['Article']) || isset($schemaTypes['BlogPosting']) ? 3 : 0,
                'Article or BlogPosting structured data was found'],
            [SiteType::Blog, $this->pathMatches($urlPaths, '#/(blog|news|articles|posts|journal)(/|$)#') >= 2 ? 2 : 0,
                'Several crawled pages sit under blog or news URLs'],
            [SiteType::Blog, $this->pathMatches($urlPaths, '#/\d{4}/\d{2}/#') >= 1 ? 1 : 0,
                'Dated article URLs were found'],

            // --- Portfolio ---
            [SiteType::Portfolio, $this->pathMatches($urlPaths, '#/(portfolio|work|projects|case-stud|gallery)#') >= 2 ? 3 : 0,
                'Several crawled pages sit under portfolio or project URLs'],
            [SiteType::Portfolio, $this->mentions($allText, ['my work', 'selected work', 'case study', 'portfolio']) ? 1 : 0,
                'Portfolio wording appears in the content'],

            // --- Service business ---
            [SiteType::ServiceBusiness, $this->pathMatches($urlPaths, '#/(services|our-services|what-we-do|solutions)(/|$)#') >= 1 ? 2 : 0,
                'The site has a services section'],
            [SiteType::ServiceBusiness, $this->anyCta($pages, ['get a quote', 'request a quote', 'book a consultation', 'get in touch', 'contact us', 'book now', 'enquire']) ? 2 : 0,
                'An enquiry or quote call to action was found'],
            [SiteType::ServiceBusiness, isset($schemaTypes['ProfessionalService']) ? 2 : 0,
                'ProfessionalService structured data was found'],
            [SiteType::ServiceBusiness, $this->mentions($allText, ['our services', 'we help', 'consultation', 'bespoke']) ? 1 : 0,
                'Service wording appears in the content'],

            // --- Landing page ---
            // Only claimable on a genuinely single-page crawl: a one-page
            // result on a site with an unreachable sitemap is a crawl
            // limitation, not a site with one page, so this needs the
            // corroborating shape of a landing page too.
            [SiteType::LandingPage, $pages->count() === 1 && $this->looksLikeLandingPage($pages->first()) ? 3 : 0,
                'The site is a single page built around one call to action'],
        ];
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @return array<string, true>
     */
    private function schemaTypes(Collection $pages): array
    {
        $types = [];

        foreach ($pages as $page) {
            foreach ($this->flattenSchema($page->structured_data) as $type) {
                $types[$type] = true;
            }
        }

        return $types;
    }

    /**
     * @return string[]
     */
    private function flattenSchema(mixed $block, int $depth = 0): array
    {
        if ($depth > 5 || ! is_array($block)) {
            return [];
        }

        $types = [];

        if (isset($block['@type'])) {
            foreach ((array) $block['@type'] as $type) {
                if (is_string($type)) {
                    $types[] = preg_replace('~^.*[/:#]~', '', trim($type));
                }
            }
        }

        foreach ($block as $value) {
            if (is_array($value)) {
                $types = array_merge($types, $this->flattenSchema($value, $depth + 1));
            }
        }

        return $types;
    }

    /**
     * @param  string[]  $paths
     */
    private function pathMatches(array $paths, string $pattern): int
    {
        return count(array_filter($paths, fn (string $path) => preg_match($pattern, $path) === 1));
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @param  string[]  $phrases
     */
    private function anyCta(Collection $pages, array $phrases): bool
    {
        foreach ($pages as $page) {
            foreach ($page->cta_elements ?? [] as $cta) {
                $text = strtolower((string) ($cta['text'] ?? ''));

                foreach ($phrases as $phrase) {
                    if (str_contains($text, $phrase)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * A bounded corpus of the site's visible wording: titles, meta
     * descriptions, headings and link text. Deliberately not the raw HTML
     * — markup is full of framework noise that matches almost anything.
     *
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
        }

        return strtolower(implode(' ', array_filter($parts)));
    }

    /**
     * @param  string[]  $phrases
     */
    private function mentions(string $corpus, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($corpus, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     * @param  string[]  $paths
     */
    private function hasPricingTiers(Collection $pages, array $paths): bool
    {
        if ($this->pathMatches($paths, '#/(pricing|plans)(/|$)#') === 0) {
            return false;
        }

        // A pricing page alone is not a SaaS signal — shops have them too.
        // Recurring-billing wording is what separates the two.
        return $this->mentions($this->textCorpus($pages), ['per month', '/mo', 'per user', 'billed annually', 'billed monthly', 'free plan']);
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     */
    private function hasPostalAddressSchema(Collection $pages): bool
    {
        foreach ($pages as $page) {
            if (in_array('PostalAddress', $this->flattenSchema($page->structured_data), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AuditPage>  $pages
     */
    private function hasTelLink(Collection $pages): bool
    {
        foreach ($pages as $page) {
            foreach ($page->links ?? [] as $link) {
                if (str_starts_with(strtolower((string) ($link['href'] ?? '')), 'tel:')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A single page with calls to action and no real navigation — the
     * shape of a campaign landing page rather than a site's homepage.
     */
    private function looksLikeLandingPage(?AuditPage $page): bool
    {
        if ($page === null) {
            return false;
        }

        $internalLinks = collect($page->links ?? [])
            ->filter(fn ($link) => is_string($link['href'] ?? null)
                && str_contains((string) $link['href'], (string) parse_url($page->url, PHP_URL_HOST)))
            ->count();

        return count($page->cta_elements ?? []) > 0 && $internalLinks <= 5;
    }
}
