<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\ConversionAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    private function module(): ConversionAuditModule
    {
        // Resolved from the container: the module now composes the
        // deterministic signal detector and checks alongside the AI
        // service, and listing those by hand here would drift.
        return app(ConversionAuditModule::class);
    }

    private function formWithRequiredFields(int $count): array
    {
        return [
            'action' => 'https://example.com/submit',
            'method' => 'post',
            'fields' => array_fill(0, $count, ['tag' => 'input', 'type' => 'text', 'name' => 'field', 'required' => true]),
        ];
    }

    public function test_page_with_no_forms_scores_100(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200,
            'forms' => [],
            // A page that is genuinely healthy for the CRO checks added in
            // §46: a specific call to action, a contact and privacy route,
            // navigation, and visible social proof. Before those checks
            // existed a bare row was "nothing wrong"; now a bare row is a
            // page with no way to act, no way to get in touch and no
            // evidence anyone has trusted it.
            'cta_elements' => [['text' => 'Get your free quote', 'href' => 'https://example.com/quote']],
            'headings' => [
                ['level' => 1, 'text' => 'Accounting for small businesses'],
                ['level' => 2, 'text' => 'What our clients say'],
            ],
            'links' => [
                ['href' => 'https://example.com/services', 'text' => 'Services'],
                ['href' => 'https://example.com/about', 'text' => 'About'],
                ['href' => 'https://example.com/contact', 'text' => 'Contact'],
                ['href' => 'https://example.com/privacy', 'text' => 'Privacy policy'],
                ['href' => 'tel:+441234567890', 'text' => 'Call us'],
            ],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }

    public function test_form_at_the_friction_threshold_is_not_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'forms' => [$this->formWithRequiredFields(6)], // exactly MAX_REQUIRED_FIELDS_BEFORE_FRICTION
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertFalse(collect($result->issues)->pluck('code')->contains('conversion.form_friction'));
    }

    public function test_form_over_the_friction_threshold_is_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'forms' => [$this->formWithRequiredFields(7)],
        ]);

        $result = $this->module()->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'conversion.form_friction');
        $this->assertNotNull($issue);
        $this->assertSame('medium', $issue->severity->value);
        $this->assertSame(7, $issue->meta['required_fields']);
    }

    public function test_only_required_fields_count_toward_friction(): void
    {
        $audit = $this->makeAudit();

        $fields = array_merge(
            array_fill(0, 2, ['tag' => 'input', 'type' => 'text', 'name' => 'required_field', 'required' => true]),
            array_fill(0, 10, ['tag' => 'input', 'type' => 'text', 'name' => 'optional_field', 'required' => false]),
        );

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'forms' => [['action' => 'x', 'method' => 'post', 'fields' => $fields]],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertFalse(collect($result->issues)->pluck('code')->contains('conversion.form_friction'));
    }
}
