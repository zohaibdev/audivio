<?php

namespace Tests\Feature\Lead;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Enums\LeadStatus;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Notifications\NewConsultationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Consultation requests and the sales workflow they feed (§55).
 *
 * The endpoint is public and unauthenticated, so the tests that matter
 * are the ones about what it refuses to take on trust.
 */
class ConsultationRequestTest extends TestCase
{
    use RefreshDatabase;

    private function website(string $domain = 'example.com'): Website
    {
        return Website::create([
            'name' => $domain, 'primary_url' => "https://{$domain}/",
            'domain' => $domain, 'normalized_domain' => $domain, 'platform' => 'unknown',
        ]);
    }

    private function audit(Website $website): Audit
    {
        return Audit::create([
            'website_id' => $website->id, 'status' => 'completed',
            'max_pages' => 25, 'completed_at' => now(),
        ]);
    }

    private function issue(Audit $audit, string $code): AuditIssue
    {
        return AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Seo,
            'severity' => IssueSeverity::Critical, 'code' => $code, 'message' => "Finding {$code}",
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function submitRequest(array $overrides = [])
    {
        return $this->postJson('/api/leads', array_merge([
            'name' => 'Dana', 'email' => 'dana@example.com', 'source' => 'report_footer',
        ], $overrides));
    }

    // --- Association ---

    public function test_a_request_from_a_report_is_attached_to_that_reports_website(): void
    {
        $website = $this->website();
        $audit = $this->audit($website);

        $this->submitRequest(['audit_id' => $audit->id])->assertStatus(201);

        $lead = Lead::firstOrFail();

        // The association the whole phase exists for: before this, a lead
        // carried a typed-in URL that could not be joined to anything.
        $this->assertSame($website->id, $lead->website_id);
        $this->assertSame($audit->id, $lead->audit_id);
    }

    public function test_the_website_is_taken_from_the_audit_not_from_the_request(): void
    {
        $mine = $this->website('mine.test');
        $theirs = $this->website('theirs.test');
        $audit = $this->audit($mine);

        // A public caller trying to file a lead against someone else's site.
        $this->submitRequest(['audit_id' => $audit->id, 'website_id' => $theirs->id])->assertStatus(201);

        $this->assertSame($mine->id, Lead::firstOrFail()->website_id);
    }

    public function test_a_request_with_no_audit_still_records_the_lead(): void
    {
        // The pricing page and the footer of a report nobody ran — there
        // is no audit to associate, and losing the lead over that would
        // be the worst outcome.
        $this->submitRequest(['website_url' => 'https://somewhere.test/'])->assertStatus(201);

        $lead = Lead::firstOrFail();

        $this->assertNull($lead->website_id);
        $this->assertSame('https://somewhere.test/', $lead->website_url);
        $this->assertSame(LeadStatus::New, $lead->status);
    }

    // --- What the visitor claims ---

    public function test_findings_are_kept_only_if_they_are_really_on_that_audit(): void
    {
        $audit = $this->audit($this->website());
        $this->issue($audit, 'seo.missing_title');

        $this->submitRequest([
            'audit_id' => $audit->id,
            'issue_codes' => ['seo.missing_title', 'seo.invented_catastrophe'],
        ])->assertStatus(201);

        // Storing the claim as-is would hand the sales team a fabricated
        // list of problems attributed to a real report.
        $this->assertSame(['seo.missing_title'], Lead::firstOrFail()->issue_codes);
    }

    public function test_findings_from_a_different_audit_are_not_accepted(): void
    {
        $mine = $this->audit($this->website('mine.test'));
        $theirs = $this->audit($this->website('theirs.test'));
        $this->issue($theirs, 'seo.their_problem');

        $this->submitRequest(['audit_id' => $mine->id, 'issue_codes' => ['seo.their_problem']])
            ->assertStatus(201);

        $this->assertNull(Lead::firstOrFail()->issue_codes);
    }

    public function test_findings_are_dropped_when_no_audit_is_named(): void
    {
        $this->submitRequest(['issue_codes' => ['seo.missing_title']])->assertStatus(201);

        // With no audit there is nothing to check them against, and an
        // unverifiable claim is not worth storing as fact.
        $this->assertNull(Lead::firstOrFail()->issue_codes);
    }

    public function test_only_real_services_can_be_requested(): void
    {
        $this->submitRequest(['requested_services' => ['seo', 'performance', 'astrology']])
            ->assertStatus(201);

        $this->assertSame(['seo', 'performance'], Lead::firstOrFail()->requested_services);
    }

    public function test_the_placement_that_produced_the_request_is_recorded(): void
    {
        $this->submitRequest(['source' => 'critical_issues'])->assertStatus(201);

        // So it is possible to see which of the four locations earns
        // requests rather than guessing.
        $this->assertSame('critical_issues', Lead::firstOrFail()->source);
    }

    public function test_an_unknown_placement_is_rejected(): void
    {
        $this->submitRequest(['source' => 'somewhere_made_up'])->assertStatus(422);
    }

    // --- Notifying sales ---

    public function test_sales_is_notified_when_a_request_comes_in(): void
    {
        Notification::fake();
        config(['leads.sales_recipients' => ['sales@example.com']]);

        $this->submitRequest()->assertStatus(201);

        Notification::assertSentOnDemand(NewConsultationRequest::class);
    }

    public function test_nobody_configured_means_no_notification_and_no_lost_lead(): void
    {
        Notification::fake();
        config(['leads.sales_recipients' => []]);

        $this->submitRequest()->assertStatus(201);

        Notification::assertNothingSent();
        $this->assertSame(1, Lead::count());
    }

    public function test_a_failing_notification_never_costs_the_lead(): void
    {
        config(['leads.sales_recipients' => ['sales@example.com']]);

        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP is down'));

        $this->submitRequest()->assertStatus(201);

        // Losing the notification costs a delay. Losing the row costs the
        // customer.
        $this->assertSame(1, Lead::count());
    }

    // --- The pipeline ---

    public function test_the_pipeline_has_a_proposal_stage(): void
    {
        $lead = Lead::create([
            'name' => 'D', 'email' => 'd@example.com',
            'source' => 'report_footer', 'status' => LeadStatus::Qualified,
        ]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'is_admin' => true]);

        $this->withHeader('Authorization', 'Bearer '.$admin->createToken('api')->plainTextToken)
            ->putJson("/api/admin/leads/{$lead->id}", ['status' => 'proposal'])
            ->assertStatus(200);

        $this->assertSame(LeadStatus::Proposal, $lead->fresh()->status);
    }

    public function test_won_replaces_converted(): void
    {
        $this->assertSame('won', LeadStatus::Won->value);
        $this->assertNull(LeadStatus::tryFrom('converted'));
        $this->assertTrue(LeadStatus::Won->isClosed());
        $this->assertFalse(LeadStatus::Proposal->isClosed());
    }

    public function test_leads_can_be_listed_for_one_website(): void
    {
        $mine = $this->website('mine.test');
        $this->submitRequest(['audit_id' => $this->audit($mine)->id])->assertStatus(201);
        $this->submitRequest(['audit_id' => $this->audit($this->website('theirs.test'))->id])->assertStatus(201);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'is_admin' => true]);

        $rows = $this->withHeader('Authorization', 'Bearer '.$admin->createToken('api')->plainTextToken)
            ->getJson("/api/admin/leads?website_id={$mine->id}")
            ->assertStatus(200)
            ->json('data.data');

        // The view a salesperson working one account wants, and only
        // possible now that a lead carries a real website id.
        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['website_id']);
    }
}
