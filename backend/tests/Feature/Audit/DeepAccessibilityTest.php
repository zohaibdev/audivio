<?php

namespace Tests\Feature\Audit;

use App\Enums\AccessibilityCheck;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\AccessibilityAuditModule;
use App\Services\Audit\WcagReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deep accessibility assessment (§43): the nine fields every issue
 * carries, the eight the report carries, and the disclaimer that must
 * travel with all of it.
 */
class DeepAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        return Audit::create([
            'website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5,
        ]);
    }

    private function addPage(Audit $audit, string $url, ?array $violations, ?array $passes = null, ?array $incomplete = null): AuditPage
    {
        return AuditPage::create([
            'audit_id' => $audit->id,
            'url' => $url,
            'http_status' => 200,
            'accessibility_violations' => $violations,
            'accessibility_passes' => $passes,
            'accessibility_incomplete' => $incomplete,
        ]);
    }

    private function contrastViolation(array $overrides = []): array
    {
        return array_merge([
            'id' => 'color-contrast',
            'impact' => 'serious',
            'description' => 'Ensures the contrast between foreground and background colors meets WCAG 2 AA ratio thresholds',
            'help' => 'Elements must meet minimum color contrast ratio thresholds',
            'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.10/color-contrast',
            'tags' => ['cat.color', 'wcag2aa', 'wcag143'],
            'nodeCount' => 3,
            'nodes' => [
                [
                    'target' => ['.hero > p.subtitle'],
                    'html' => '<p class="subtitle">Audit your site</p>',
                    'failureSummary' => 'Element has insufficient color contrast of 2.4:1',
                ],
            ],
        ], $overrides);
    }

    private function runModule(Audit $audit)
    {
        return app(AccessibilityAuditModule::class)->run($audit->fresh());
    }

    // --- Issue data: the nine fields the spec names ---

    public function test_an_issue_carries_every_field_the_spec_requires(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [$this->contrastViolation()]);

        $issue = $this->runModule($audit)->issues[0];
        $meta = $issue->meta;

        // Page URL + element selector + element markup
        $this->assertSame('https://example.com/', $meta['elements'][0]['page_url']);
        $this->assertSame('.hero > p.subtitle', $meta['elements'][0]['selector']);
        $this->assertStringContainsString('<p class="subtitle">', $meta['elements'][0]['html']);
        // Evidence — axe's own explanation of why *this* element failed
        $this->assertStringContainsString('2.4:1', $meta['elements'][0]['evidence']);
        // Severity, description, why it matters, WCAG reference
        $this->assertSame('high', $issue->severity->value);
        $this->assertStringContainsString('contrast', $meta['description']);
        $this->assertStringContainsString('low vision', $meta['why_it_matters']);
        $this->assertSame('WCAG 1.4.3 (Level AA)', $meta['wcag_label']);
        // Recommendation — advice, not a bare URL
        $this->assertStringContainsString('Elements must meet', $issue->recommendation);
        $this->assertStringContainsString('dequeuniversity.com', $issue->recommendation);
    }

    public function test_an_axe_rule_is_grouped_under_a_named_check_area(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [$this->contrastViolation()]);

        $meta = $this->runModule($audit)->issues[0]->meta;

        $this->assertSame('contrast', $meta['check']);
        $this->assertSame('Contrast', $meta['check_label']);
    }

    public function test_every_spec_check_area_has_a_rule_mapped_to_it(): void
    {
        $samples = [
            'image-alt' => AccessibilityCheck::AltText,
            'label' => AccessibilityCheck::Labels,
            'button-name' => AccessibilityCheck::Buttons,
            'link-name' => AccessibilityCheck::Links,
            'aria-roles' => AccessibilityCheck::Aria,
            'heading-order' => AccessibilityCheck::HeadingHierarchy,
            'color-contrast' => AccessibilityCheck::Contrast,
            'nested-interactive' => AccessibilityCheck::Keyboard,
            'autocomplete-valid' => AccessibilityCheck::Forms,
            'region' => AccessibilityCheck::Landmarks,
            'list' => AccessibilityCheck::SemanticHtml,
            'td-headers-attr' => AccessibilityCheck::Tables,
            'frame-title' => AccessibilityCheck::Iframes,
            'html-has-lang' => AccessibilityCheck::Language,
            'bypass' => AccessibilityCheck::SkipLinks,
            'tabindex' => AccessibilityCheck::Focus,
        ];

        foreach ($samples as $rule => $expected) {
            $this->assertSame($expected, AccessibilityCheck::forAxeRule($rule), "axe rule {$rule}");
        }
    }

    public function test_an_unmapped_axe_rule_is_still_reported_rather_than_dropped(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [
            $this->contrastViolation(['id' => 'some-brand-new-axe-rule', 'tags' => []]),
        ]);

        $result = $this->runModule($audit);

        // An axe upgrade that adds rules must not silently lose findings
        // because the map has not caught up.
        $this->assertCount(1, $result->issues);
        $this->assertSame('other', $result->issues[0]->meta['check']);
    }

    // --- WCAG references ---

    public function test_wcag_criteria_and_level_are_parsed_from_axe_tags(): void
    {
        $reference = WcagReference::fromAxeTags(['cat.color', 'wcag2aa', 'wcag143']);

        $this->assertSame(['1.4.3'], $reference['criteria']);
        $this->assertSame('AA', $reference['level']);
        $this->assertFalse($reference['is_best_practice']);
    }

    public function test_a_best_practice_rule_carries_no_wcag_reference(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [
            $this->contrastViolation(['id' => 'region', 'tags' => ['cat.keyboard', 'best-practice']]),
        ]);

        $meta = $this->runModule($audit)->issues[0]->meta;

        // Inventing a criterion for a rule that maps to none would be a
        // standards claim the finding cannot support.
        $this->assertSame([], $meta['wcag']['criteria']);
        $this->assertNull($meta['wcag_label']);
        $this->assertTrue($meta['is_best_practice']);
    }

    public function test_the_strictest_conformance_level_wins(): void
    {
        $reference = WcagReference::fromAxeTags(['wcag2a', 'wcag21aa', 'wcag412']);

        // Satisfying the rule requires AA, so reporting it as A would
        // understate what the fix actually takes.
        $this->assertSame('AA', $reference['level']);
    }

    // --- Report data: the eight fields the spec names ---

    public function test_the_report_counts_issues_by_severity(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [
            $this->contrastViolation(['id' => 'color-contrast', 'impact' => 'critical']),
            $this->contrastViolation(['id' => 'label', 'impact' => 'serious']),
            $this->contrastViolation(['id' => 'region', 'impact' => 'moderate']),
            $this->contrastViolation(['id' => 'tabindex', 'impact' => 'minor']),
        ]);

        $severities = collect($this->runModule($audit)->issues)
            ->countBy(fn ($i) => $i->severity->value);

        $this->assertSame(1, $severities['critical']);
        $this->assertSame(1, $severities['high']);
        $this->assertSame(1, $severities['medium']);
        $this->assertSame(1, $severities['low']);
    }

    public function test_the_report_records_affected_pages_and_elements(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [$this->contrastViolation(['nodeCount' => 3])]);
        $this->addPage($audit, 'https://example.com/about', [$this->contrastViolation(['nodeCount' => 2])]);

        $meta = $this->runModule($audit)->issues[0]->meta;

        $this->assertSame(2, $meta['affected_page_count']);
        $this->assertSame(5, $meta['affected_element_count']);
        $this->assertContains('https://example.com/about', $meta['affected_pages']);
    }

    public function test_passed_checks_come_from_what_axe_actually_ran(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [], ['image-alt', 'label', 'html-has-lang']);

        $coverage = $this->runModule($audit)->breakdown['coverage'];

        $this->assertSame(3, $coverage['passed_checks']);
        $this->assertContains('image-alt', $coverage['passed_rules']);
    }

    public function test_incomplete_results_are_reported_separately_from_passes(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [], ['image-alt'], ['color-contrast']);

        $coverage = $this->runModule($audit)->breakdown['coverage'];

        // "axe could not decide" is not "fine" — counting it as a pass
        // would inflate the clean-bill-of-health impression.
        $this->assertSame(1, $coverage['passed_checks']);
        $this->assertSame(['color-contrast'], $coverage['needs_review']);
    }

    public function test_coverage_distinguishes_a_scanned_page_from_an_unscanned_one(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', []);
        // axe failed on this page, so it has no violations *and* no result.
        $this->addPage($audit, 'https://example.com/about', null);

        $coverage = $this->runModule($audit)->breakdown['coverage'];

        // Without this, a partial scan is presented as a complete one.
        $this->assertSame(2, $coverage['pages_crawled']);
        $this->assertSame(1, $coverage['pages_scanned']);
    }

    public function test_every_check_area_is_listed_including_ones_with_no_findings(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [$this->contrastViolation()]);

        $checks = collect($this->runModule($audit)->breakdown['checks'])->keyBy('check');

        // Listing areas with nothing found is the only way a report can
        // say a check area passed at all.
        $this->assertCount(count(AccessibilityCheck::reportable()), $checks);
        $this->assertFalse($checks['contrast']['passed']);
        $this->assertSame(1, $checks['contrast']['issue_count']);
        $this->assertTrue($checks['tables']['passed']);
        $this->assertSame(0, $checks['tables']['issue_count']);
    }

    public function test_every_check_area_explains_why_it_matters(): void
    {
        foreach (AccessibilityCheck::reportable() as $check) {
            $why = $check->whyItMatters();

            $this->assertNotSame('', $why, "{$check->value} has no explanation");
            // Written for a site owner: it has to say who is affected, not
            // restate the rule id.
            $this->assertGreaterThan(60, strlen($why), "{$check->value} explanation is too thin");
        }
    }

    // --- The disclaimer ---

    public function test_every_result_carries_the_automated_assessment_disclaimer(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [$this->contrastViolation()]);

        $disclaimer = $this->runModule($audit)->breakdown['disclaimer'];

        $this->assertNotEmpty($disclaimer['summary']);
        $this->assertStringContainsString('automated', strtolower($disclaimer['summary']));
        $this->assertStringContainsString('not a legal compliance audit', strtolower($disclaimer['summary']));
    }

    public function test_a_clean_scan_still_carries_the_disclaimer(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [], ['image-alt']);

        $result = $this->runModule($audit);

        // A perfect score is exactly where the caveat matters most: it is
        // the result most likely to be quoted as proof of compliance.
        $this->assertSame(100, $result->score);
        $this->assertNotEmpty($result->breakdown['disclaimer']['summary']);
        $this->assertStringContainsString('not a conformance rating', $result->breakdown['disclaimer']['score_note']);
    }

    public function test_the_disclaimer_never_claims_compliance_or_certification(): void
    {
        $text = strtolower(implode(' ', config('accessibility.disclaimer')));

        // The wording exists precisely so this product is never quotable as
        // certifying anything. A future edit that reintroduces these words
        // should fail here rather than in a lawsuit.
        // Only affirmative claims are forbidden. 'certification' appears in
        // the text, but as something this product explicitly is not.
        foreach (['certified', 'guarantees compliance', 'ada compliant', 'fully compliant', 'is compliant'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "disclaimer must not say '{$forbidden}'");
        }

        $this->assertStringContainsString('not a legal compliance audit', $text);
        $this->assertStringContainsString('manual testing', $text);
        $this->assertStringContainsString('assistive technology', $text);
    }

    // --- Aggregation behaviour is unchanged ---

    public function test_older_audits_without_node_detail_still_produce_selectors(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/', [[
            'id' => 'image-alt',
            'impact' => 'critical',
            'description' => 'Images must have alternate text',
            'help' => 'Images must have alternate text',
            'helpUrl' => 'https://example.test/rule',
            'nodeCount' => 2,
            // Pre-§43 shape: targets only, no `nodes`.
            'targets' => [['img.logo'], ['img.banner']],
        ]]);

        $elements = $this->runModule($audit)->issues[0]->meta['elements'];

        $this->assertSame('img.logo', $elements[0]['selector']);
        $this->assertNull($elements[0]['html']);
        $this->assertSame('https://example.com/', $elements[0]['page_url']);
    }
}
