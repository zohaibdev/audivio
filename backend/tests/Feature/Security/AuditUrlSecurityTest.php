<?php

namespace Tests\Feature\Security;

use App\DTOs\CrawledPageData;
use App\Models\Audit;
use App\Models\UsageLog;
use App\Models\Website;
use App\Services\Crawler\PlaywrightCrawlerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The audit entry point, exercised the way an attacker would reach it:
 * through the API, not through the validator class.
 *
 * The unit tests in Tests\Unit\Services\Crawler prove UrlSecurityValidator
 * and UrlNormalizer make the right decisions. This file proves the
 * controller actually consults them — that nothing gets stored, queued or
 * crawled on the strength of frontend validation alone.
 */
class AuditUrlSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]);

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->andReturn(new CrawledPageData(
                url: 'https://example.com/',
                httpStatus: 200,
                redirectChain: [],
                loadTimeMs: 500,
                screenshotDesktopPath: null,
                screenshotMobilePath: null,
                title: 'Home',
                metaDescription: str_repeat('a', 100),
                canonicalUrl: 'https://example.com/',
                headings: [['level' => 1, 'text' => 'Welcome']],
                links: [],
                images: [],
                forms: [],
                structuredData: [],
                ctaElements: [],
                htmlSizeBytes: 1000,
                accessibilityViolations: [],
            ));
        });
    }

    /**
     * Every payload in the sweeps below must be rejected, and rejection
     * must leave no trace: no website row to be re-audited later, no audit
     * row, and no usage log that would burn the caller's free audit.
     */
    private function assertRejectedWithNoSideEffects(string $url): void
    {
        $response = $this->postJson('/api/audits', ['url' => $url]);

        $this->assertSame(
            422,
            $response->status(),
            "Expected {$url} to be rejected with 422, got {$response->status()}",
        );

        $this->assertSame(0, Website::count(), "A website row was created for {$url}");
        $this->assertSame(0, Audit::count(), "An audit row was created for {$url}");
        $this->assertSame(0, UsageLog::count(), "A usage log row was created for {$url}");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            // Malformed
            'not a url' => ['not a url at all'],
            'scheme only' => ['https://'],
            'no host' => ['https:///path-only'],
            'spaces' => ['http://exa mple.com/'],

            // Dangerous schemes
            'javascript' => ['javascript:alert(1)'],
            'file' => ['file:///etc/passwd'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'ftp' => ['ftp://example.com/'],
            'gopher' => ['gopher://example.com:70/_'],

            // Loopback and localhost
            'localhost' => ['http://localhost/'],
            'localhost uppercase' => ['http://LOCALHOST/'],
            'localhost trailing dot' => ['http://localhost./'],
            'localhost subdomain' => ['http://api.localhost/'],
            'loopback ipv4' => ['http://127.0.0.1/'],
            'loopback ipv4 alternate' => ['http://127.0.0.2/'],
            'loopback ipv6' => ['http://[::1]/'],

            // Private ranges
            'private 10/8' => ['http://10.1.2.3/'],
            'private 172.16/12' => ['http://172.20.0.1/'],
            'private 192.168/16' => ['http://192.168.0.1/'],
            'carrier grade nat' => ['http://100.64.0.1/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            'ipv4 mapped ipv6 private' => ['http://[::ffff:10.0.0.1]/'],

            // Link-local and cloud metadata
            'link local' => ['http://169.254.0.1/'],
            'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'gcp metadata' => ['http://169.254.169.254/computeMetadata/v1/'],
            'nat64 wrapped metadata' => ['http://[64:ff9b::a9fe:a9fe]/'],

            // Alternate integer spellings of loopback
            'decimal ip' => ['http://2130706433/'],
            'octal ip' => ['http://0177.0.0.1/'],
            'hex ip' => ['http://0x7f.0.0.1/'],

            // Credentials
            'embedded credentials' => ['http://user:pass@example.com/'],
            'credentials masking an internal host' => ['http://example.com@127.0.0.1/'],

            // Non-web ports
            'redis port' => ['http://example.com:6379/'],
            'postgres port' => ['http://example.com:5432/'],
            'high port' => ['http://example.com:8080/'],

            // Injection payloads
            'shell metacharacters' => ['http://example.com;curl evil.test/'],
            'shell substitution' => ['http://$(id).example.com/'],
            'shell pipe' => ['http://example.com|whoami/'],
            'sql injection' => ["http://example.com' OR '1'='1"],
            'xss script tag' => ['http://<script>alert(1)</script>/'],
            'xss in host' => ['http://exam"ple.com/'],
            'crlf injection' => ["http://example.com/\r\nX-Injected: 1"],
            // Mid-string, not trailing: a trailing NUL is removed by the
            // request's own trim() before it can mean anything, whereas an
            // embedded one is what truncates a path for a C-level parser.
            'null byte' => ["http://example.com/\0/admin"],
        ];
    }

    /**
     * @dataProvider unsafeUrlProvider
     */
    public function test_unsafe_url_is_rejected_by_the_api(string $url): void
    {
        // The throttle is a separate protection with its own test below;
        // excluding it here keeps this sweep from turning into a rate-limit
        // test partway through.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->assertRejectedWithNoSideEffects($url);
    }

    public function test_sql_injection_payload_does_not_reach_the_database(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->postJson('/api/audits', ['url' => "https://example.com/'; DROP TABLE audits; --"]);

        // Whether the URL was accepted or rejected, the schema must be
        // intact — Eloquent parameterizes, and this asserts that holds for
        // a value that reaches it straight from user input.
        $this->assertSame(0, Audit::whereRaw('1 = 0')->count());
        $this->assertTrue(\Schema::hasTable('audits'));
    }

    // --- The URL that passed validation is the URL that gets stored ---

    public function test_the_stored_url_is_the_normalized_one(): void
    {
        $this->postJson('/api/audits', ['url' => 'HTTPS://Example.COM:443/Pricing#plans'])
            ->assertStatus(202);

        $website = Website::sole();

        $this->assertSame('https://example.com/Pricing', $website->primary_url);
        $this->assertSame('example.com', $website->domain);
    }

    public function test_the_stored_domain_is_lowercased_and_stripped_of_the_dns_root_dot(): void
    {
        $this->postJson('/api/audits', ['url' => 'https://EXAMPLE.com./'])->assertStatus(202);

        $this->assertSame('example.com', Website::sole()->domain);
    }

    // --- Guest cannot bypass audit restrictions through the API ---

    public function test_guest_cannot_raise_its_page_limit_through_the_payload(): void
    {
        config(['entitlements.guest.max_pages' => 3]);

        $response = $this->postJson('/api/audits', [
            'url' => 'https://example.com',
            'max_pages' => 500,
        ])->assertStatus(202);

        $this->assertSame(3, Audit::find($response->json('data.audit_id'))->max_pages);
    }

    public function test_guest_cannot_assign_itself_a_user_id_through_the_payload(): void
    {
        $response = $this->postJson('/api/audits', [
            'url' => 'https://example.com',
            'user_id' => 1,
        ])->assertStatus(202);

        $this->assertNull(Audit::find($response->json('data.audit_id'))->user_id);
        $this->assertNull(Website::sole()->user_id);
    }

    public function test_guest_cannot_set_the_audit_status_through_the_payload(): void
    {
        $response = $this->postJson('/api/audits', [
            'url' => 'https://example.com',
            'status' => 'completed',
        ])->assertStatus(202);

        $this->assertNotSame('completed', $response->json('data.status'));
    }

    public function test_guest_second_audit_is_refused_and_creates_nothing(): void
    {
        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        $this->postJson('/api/audits', ['url' => 'https://example.com/pricing'])
            ->assertStatus(429);

        // The refusal must be total: no half-created website waiting to be
        // picked up, and no second usage log.
        $this->assertSame(1, Audit::count());
        $this->assertSame(1, Website::count());
        $this->assertSame(1, UsageLog::count());
    }

    public function test_guest_cannot_reuse_its_free_audit_by_varying_the_url_spelling(): void
    {
        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        // The lifetime limit is keyed on the caller, not the target, so a
        // different-looking URL from the same IP is still a second audit.
        $this->postJson('/api/audits', ['url' => 'HTTPS://EXAMPLE.COM/?x=1'])
            ->assertStatus(429);

        $this->assertSame(1, Audit::count());
    }

    public function test_audit_creation_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/audits', ['url' => 'https://example.com']);
        }

        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(429);
    }
}
