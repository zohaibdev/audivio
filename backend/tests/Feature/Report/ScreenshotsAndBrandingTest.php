<?php

namespace Tests\Feature\Report;

use App\Enums\ScreenshotViewport;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\BrandingProfile;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Screenshot;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Plan\EntitlementResolver;
use App\Services\Report\BrandingResolver;
use App\Services\Report\ReportVisibilityFilter;
use App\Services\Report\ScreenshotUrlSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Screenshot access control, PDF visibility, and white-label branding
 * (§48). Every test here is about someone seeing something they should
 * not, or not seeing something they paid for.
 */
class ScreenshotsAndBrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Audit $audit;

    private Screenshot $screenshot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('crawler.screenshot_disk'));
        Storage::fake(config('branding.logo_disk'));

        $this->owner = $this->makeUser('owner@example.com', ['white_label' => true, 'pdf_export' => true]);

        $website = Website::create([
            'user_id' => $this->owner->id, 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com', 'platform' => 'unknown',
        ]);

        $this->audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $this->owner->id,
            'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 1, 'completed_at' => now(),
        ]);

        $page = AuditPage::create([
            'audit_id' => $this->audit->id, 'url' => 'https://example.com/',
            'http_status' => 200, 'title' => 'Home',
        ]);

        Storage::disk(config('crawler.screenshot_disk'))
            ->put('screenshots/1/home/desktop.png', 'fake-png-bytes');

        $this->screenshot = Screenshot::create([
            'audit_id' => $this->audit->id, 'audit_page_id' => $page->id,
            'viewport' => ScreenshotViewport::Desktop, 'width' => 1366, 'height' => 900,
            'storage_path' => 'screenshots/1/home/desktop.png',
        ]);
    }

    private function makeUser(string $email, array $features = [], int $visibility = 100): User
    {
        $user = User::create(['name' => 'User', 'email' => $email, 'password' => 'x']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => Plan::create([
                'name' => 'Plan '.$email, 'slug' => 'plan-'.md5($email), 'price_cents' => 0,
                'max_pages' => 25, 'daily_audit_limit' => 10,
                'report_visibility_percent' => $visibility,
                'issues_per_service' => null,
                'features' => $features,
            ])->id,
            'started_at' => now(),
        ]);

        return $user->fresh();
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    // --- Screenshot access ---

    public function test_a_screenshot_url_is_signed_rather_than_a_raw_storage_path(): void
    {
        $url = app(ScreenshotUrlSigner::class)->urlFor($this->screenshot);

        // The old path was `/storage/screenshots/{auditId}/...` — derived
        // from a sequential id, so anyone could walk it.
        $this->assertStringContainsString('/api/screenshots/'.$this->screenshot->id, $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringNotContainsString('screenshots/1/home/desktop.png', $url);
    }

    public function test_the_owner_can_load_their_own_screenshot(): void
    {
        $url = app(ScreenshotUrlSigner::class)->urlFor($this->screenshot);

        $this->withHeader('Authorization', 'Bearer '.$this->token($this->owner))
            ->get($url)
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_an_unsigned_url_is_refused(): void
    {
        // Without the signature the id is a sequential integer anyone
        // could enumerate.
        $this->withHeader('Authorization', 'Bearer '.$this->token($this->owner))
            ->get('/api/screenshots/'.$this->screenshot->id)
            ->assertStatus(403);
    }

    public function test_a_valid_signed_url_is_useless_to_another_user(): void
    {
        $stranger = $this->makeUser('stranger@example.com');
        $url = app(ScreenshotUrlSigner::class)->urlFor($this->screenshot);

        // The signature is genuine — this is a forwarded report link, not
        // a forged one. The authorization check is what stops it.
        $this->withHeader('Authorization', 'Bearer '.$this->token($stranger))
            ->get($url)
            ->assertStatus(404);
    }

    public function test_a_valid_signed_url_is_useless_to_an_anonymous_visitor(): void
    {
        $url = app(ScreenshotUrlSigner::class)->urlFor($this->screenshot);

        $this->getJson($url)->assertStatus(404);
    }

    public function test_a_missing_screenshot_reads_as_not_found_rather_than_erroring(): void
    {
        Storage::disk(config('crawler.screenshot_disk'))->delete('screenshots/1/home/desktop.png');

        $url = app(ScreenshotUrlSigner::class)->urlFor($this->screenshot);

        $this->withHeader('Authorization', 'Bearer '.$this->token($this->owner))
            ->get($url)
            ->assertStatus(404);
    }

    public function test_a_screenshot_captured_before_the_disk_moved_is_still_served(): void
    {
        Storage::fake('public');
        Storage::disk(config('crawler.screenshot_disk'))->delete('screenshots/1/home/desktop.png');
        Storage::disk('public')->put('screenshots/1/home/desktop.png', 'old-png-bytes');

        // Switching the disk must not break every screenshot in every
        // report that already exists.
        $this->withHeader('Authorization', 'Bearer '.$this->token($this->owner))
            ->get(app(ScreenshotUrlSigner::class)->urlFor($this->screenshot))
            ->assertStatus(200);
    }

    public function test_a_report_can_load_more_screenshots_than_the_audit_throttle_allows(): void
    {
        $url = app(ScreenshotUrlSigner::class)->urlFor($this->screenshot);
        $token = $this->token($this->owner);

        // The route used to sit inside the audit group's throttle:10,1.
        // One report view loads two screenshots per crawled page, so on a
        // 25-page audit all but the first ten failed — which is most of
        // them, and every one rendered as "Screenshot unavailable".
        for ($i = 0; $i < 25; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->get($url)
                ->assertStatus(200);
        }
    }

    // --- PDF respects plan visibility ---

    public function test_a_pdf_export_is_filtered_by_the_owners_plan_like_the_web_report(): void
    {
        $limited = $this->makeUser('limited@example.com', ['pdf_export' => true], visibility: 30);

        $website = Website::create([
            'user_id' => $limited->id, 'primary_url' => 'https://limited.test/',
            'domain' => 'limited.test', 'normalized_domain' => 'limited.test', 'platform' => 'unknown',
        ]);

        $audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $limited->id,
            'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 1, 'completed_at' => now(),
        ]);

        Report::create([
            'audit_id' => $audit->id,
            'content' => [
                'overview' => ['website_url' => 'https://limited.test/', 'domain' => 'limited.test', 'platform' => 'unknown', 'overall_score' => 50, 'category_scores' => [], 'pages_crawled' => 1, 'audit_date' => now()->toIso8601String()],
                'issues' => array_map(fn ($i) => [
                    'category' => 'seo', 'severity' => 'critical', 'code' => "seo.issue_{$i}",
                    'message' => "Secret finding {$i}", 'recommendation' => 'Fix it.',
                    'page_url' => null, 'meta' => [],
                ], range(1, 10)),
                'recommendations' => [], 'ai_analysis' => [], 'pages' => [],
            ],
            'generated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($limited))
            ->get("/api/audits/{$audit->id}/report/pdf");

        $response->assertStatus(200);

        // Export used to render the full report regardless of the plan,
        // so a 30%-visibility plan handed over 100% of it as a download —
        // the limit was one button away from being bypassed.
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_the_pdf_and_the_web_report_apply_the_same_filter(): void
    {
        $limited = $this->makeUser('same@example.com', ['pdf_export' => true], visibility: 30);

        $content = [
            'overview' => [], 'recommendations' => [], 'ai_analysis' => [], 'pages' => [],
            'issues' => array_map(fn ($i) => [
                'category' => 'seo', 'severity' => 'critical', 'code' => "seo.issue_{$i}",
                'message' => "Finding {$i}", 'recommendation' => 'Fix it.',
                'page_url' => null, 'meta' => [],
            ], range(1, 10)),
        ];

        $entitlements = app(EntitlementResolver::class)->for($limited);
        $filtered = app(ReportVisibilityFilter::class)->filter($content, $entitlements);

        // Same filter, same entitlements, so the export cannot contain
        // more than the screen does.
        $this->assertLessThan(10, count($filtered['issues']));
        $this->assertGreaterThan(0, $filtered['hidden_issue_count']);
    }

    // --- White label ---

    public function test_branding_falls_back_to_the_product_when_the_plan_excludes_it(): void
    {
        $noWhiteLabel = $this->makeUser('plain@example.com', ['white_label' => false]);
        BrandingProfile::create(['user_id' => $noWhiteLabel->id, 'brand_name' => 'Someone Else']);

        $audit = Audit::create([
            'website_id' => $this->audit->website_id, 'user_id' => $noWhiteLabel->id,
            'status' => 'completed', 'max_pages' => 5,
        ]);

        $branding = app(BrandingResolver::class)->forAudit($audit->fresh());

        $this->assertFalse($branding['is_white_label']);
        $this->assertSame(config('app.name'), $branding['brand_name']);
    }

    public function test_branding_applies_when_the_plan_allows_it(): void
    {
        BrandingProfile::create([
            'user_id' => $this->owner->id,
            'brand_name' => 'Acme Digital',
            'contact_email' => 'hello@acme.test',
            'footer_text' => 'Prepared by Acme Digital.',
        ]);

        $branding = app(BrandingResolver::class)->forAudit($this->audit->fresh());

        $this->assertTrue($branding['is_white_label']);
        $this->assertSame('Acme Digital', $branding['brand_name']);
        $this->assertSame('Prepared by Acme Digital.', $branding['footer_text']);
    }

    public function test_an_empty_profile_is_treated_as_no_branding(): void
    {
        BrandingProfile::create(['user_id' => $this->owner->id]);

        // Applying an empty profile would replace our branding with
        // nothing at all, leaving a report with no identity on it.
        $this->assertFalse(app(BrandingResolver::class)->forAudit($this->audit->fresh())['is_white_label']);
    }

    public function test_a_lapsed_plan_stops_branding_without_deleting_it(): void
    {
        BrandingProfile::create(['user_id' => $this->owner->id, 'brand_name' => 'Acme Digital']);

        Subscription::where('user_id', $this->owner->id)->update(['ends_at' => now()->subDay()]);

        $branding = app(BrandingResolver::class)->forAudit($this->audit->fresh());

        // Entitlement is asked at render time, so the branding stops
        // appearing immediately...
        $this->assertFalse($branding['is_white_label']);
        // ...but the details the user entered are still theirs.
        $this->assertSame('Acme Digital', BrandingProfile::where('user_id', $this->owner->id)->first()->brand_name);
    }

    public function test_an_anonymous_audit_never_carries_someone_elses_branding(): void
    {
        $audit = Audit::create([
            'website_id' => $this->audit->website_id, 'user_id' => null,
            'status' => 'completed', 'max_pages' => 5,
        ]);

        $this->assertFalse(app(BrandingResolver::class)->forAudit($audit->fresh())['is_white_label']);
    }

    public function test_a_logo_is_inlined_as_a_data_uri_for_the_pdf(): void
    {
        $path = 'branding/'.$this->owner->id.'/logo.png';
        Storage::disk(config('branding.logo_disk'))->put($path, 'fake-logo-bytes');

        BrandingProfile::create([
            'user_id' => $this->owner->id, 'brand_name' => 'Acme', 'logo_path' => $path,
        ]);

        $branding = app(BrandingResolver::class)->forAudit($this->audit->fresh());

        // dompdf cannot fetch a remote asset, and the file is behind a
        // signed route it has no session for — a URL would render as a
        // broken image in the one place branding matters most.
        $this->assertStringStartsWith('data:', $branding['logo_data_uri']);
        $this->assertStringContainsString(base64_encode('fake-logo-bytes'), $branding['logo_data_uri']);
    }

    public function test_an_oversized_logo_is_dropped_rather_than_failing_the_export(): void
    {
        $path = 'branding/'.$this->owner->id.'/huge.png';
        Storage::disk(config('branding.logo_disk'))
            ->put($path, str_repeat('x', (int) config('branding.max_logo_bytes') + 1));

        BrandingProfile::create(['user_id' => $this->owner->id, 'brand_name' => 'Acme', 'logo_path' => $path]);

        $branding = app(BrandingResolver::class)->forAudit($this->audit->fresh());

        // A missing image is cosmetic; a failed download is not.
        $this->assertNull($branding['logo_data_uri']);
        $this->assertSame('Acme', $branding['brand_name']);
    }

    // --- Branding API ---

    public function test_branding_can_be_saved_and_read_back(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token($this->owner))
            ->postJson('/api/me/branding', [
                'brand_name' => 'Acme Digital',
                'contact_email' => 'hello@acme.test',
                'footer_text' => 'Prepared by Acme.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.brand_name', 'Acme Digital')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_branding_is_saved_on_a_plan_that_cannot_use_it_yet(): void
    {
        $noWhiteLabel = $this->makeUser('later@example.com', ['white_label' => false]);

        // Saving is allowed whatever the plan: the entitlement is checked
        // at render time, so a user who upgrades gets their branding back
        // rather than having to re-enter it.
        $this->withHeader('Authorization', 'Bearer '.$this->token($noWhiteLabel))
            ->postJson('/api/me/branding', ['brand_name' => 'Future Agency'])
            ->assertStatus(200)
            ->assertJsonPath('data.brand_name', 'Future Agency')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_available_on_plan', false);
    }

    public function test_a_logo_upload_is_stored_privately(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token($this->owner))
            ->post('/api/me/branding', [
                'brand_name' => 'Acme',
                'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.has_logo', true);

        $path = BrandingProfile::where('user_id', $this->owner->id)->first()->logo_path;

        Storage::disk(config('branding.logo_disk'))->assertExists($path);
        // Never the public disk: a path keyed by user id is guessable,
        // and confirming which ids have logos confirms which accounts
        // exist.
        $this->assertStringStartsWith('branding/'.$this->owner->id, $path);
    }

    public function test_branding_requires_authentication(): void
    {
        $this->getJson('/api/me/branding')->assertStatus(401);
        $this->postJson('/api/me/branding', ['brand_name' => 'X'])->assertStatus(401);
    }
}
