<?php

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\SafeHttpClient;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlSecurityValidator;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The redirect chain is where a validated URL stops being the URL that
 * gets fetched, so it is the part of SSRF protection worth testing hardest:
 * every case here starts at a host that legitimately passes validation.
 */
class SafeHttpClientTest extends TestCase
{
    private SafeHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new SafeHttpClient(new UrlSecurityValidator);
    }

    private function redirectTo(string $location, int $status = 302): PromiseInterface
    {
        return Http::response('', $status, ['Location' => $location]);
    }

    public function test_fetches_a_safe_url_and_returns_its_body(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => Http::response('User-agent: *', 200)]);

        $result = $this->client->get('http://8.8.8.8/robots.txt');

        $this->assertNotNull($result);
        $this->assertSame('User-agent: *', $result['body']);
        $this->assertSame('http://8.8.8.8/robots.txt', $result['url']);
    }

    public function test_initial_url_pointing_at_an_internal_address_throws(): void
    {
        Http::fake();

        $this->expectException(SsrfValidationException::class);

        $this->client->get('http://169.254.169.254/latest/meta-data/');
    }

    public function test_redirect_to_the_cloud_metadata_endpoint_is_blocked(): void
    {
        // The classic bypass: a host that passes every up-front check
        // answers with a 302 into the instance metadata service.
        Http::fake([
            'http://8.8.8.8/robots.txt' => $this->redirectTo('http://169.254.169.254/latest/meta-data/iam/'),
        ]);

        $this->expectException(SsrfValidationException::class);

        $this->client->get('http://8.8.8.8/robots.txt');
    }

    public function test_redirect_to_localhost_is_blocked(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => $this->redirectTo('http://localhost:80/admin')]);

        $this->expectException(SsrfValidationException::class);

        $this->client->get('http://8.8.8.8/robots.txt');
    }

    public function test_redirect_to_a_private_ip_is_blocked(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => $this->redirectTo('http://192.168.1.1/')]);

        $this->expectException(SsrfValidationException::class);

        $this->client->get('http://8.8.8.8/robots.txt');
    }

    public function test_redirect_to_a_non_http_scheme_is_blocked(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => $this->redirectTo('file:///etc/passwd')]);

        $this->expectException(SsrfValidationException::class);

        $this->client->get('http://8.8.8.8/robots.txt');
    }

    public function test_a_relative_redirect_is_resolved_against_its_origin_and_followed(): void
    {
        Http::fake([
            'http://8.8.8.8/robots.txt' => $this->redirectTo('/robots-real.txt'),
            'http://8.8.8.8/robots-real.txt' => Http::response('Sitemap: http://8.8.8.8/sitemap.xml', 200),
        ]);

        $result = $this->client->get('http://8.8.8.8/robots.txt');

        $this->assertNotNull($result);
        $this->assertSame('http://8.8.8.8/robots-real.txt', $result['url']);
    }

    public function test_a_scheme_relative_redirect_to_an_internal_host_is_blocked(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => $this->redirectTo('//127.0.0.1/')]);

        $this->expectException(SsrfValidationException::class);

        $this->client->get('http://8.8.8.8/robots.txt');
    }

    public function test_a_safe_cross_host_redirect_is_followed(): void
    {
        Http::fake([
            'http://8.8.8.8/robots.txt' => $this->redirectTo('http://1.1.1.1/robots.txt'),
            'http://1.1.1.1/robots.txt' => Http::response('User-agent: *', 200),
        ]);

        $result = $this->client->get('http://8.8.8.8/robots.txt');

        $this->assertNotNull($result);
        $this->assertSame('http://1.1.1.1/robots.txt', $result['url']);
        $this->assertSame('User-agent: *', $result['body']);
    }

    public function test_a_redirect_chain_longer_than_the_budget_is_abandoned(): void
    {
        config(['crawler.max_redirects' => 2]);

        // Every hop is individually safe; the chain itself is the problem.
        Http::fake(['http://8.8.8.8/*' => $this->redirectTo('http://8.8.8.8/next')]);

        $this->assertNull($this->client->get('http://8.8.8.8/robots.txt'));
    }

    public function test_a_redirect_with_no_location_header_gives_up(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => Http::response('', 302)]);

        $this->assertNull($this->client->get('http://8.8.8.8/robots.txt'));
    }

    public function test_an_unsuccessful_response_returns_null(): void
    {
        Http::fake(['http://8.8.8.8/robots.txt' => Http::response('nope', 404)]);

        $this->assertNull($this->client->get('http://8.8.8.8/robots.txt'));
    }

    public function test_a_response_declaring_an_oversized_content_length_is_rejected(): void
    {
        config(['crawler.max_response_bytes' => 1024]);

        Http::fake([
            'http://8.8.8.8/sitemap.xml' => Http::response('short', 200, ['Content-Length' => '99999999']),
        ]);

        $this->assertNull($this->client->get('http://8.8.8.8/sitemap.xml'));
    }

    public function test_an_oversized_body_is_rejected_even_without_a_content_length_header(): void
    {
        // Chunked responses carry no Content-Length, so a header-only check
        // would let an unbounded body straight through.
        config(['crawler.max_response_bytes' => 1024]);

        Http::fake(['http://8.8.8.8/sitemap.xml' => Http::response(str_repeat('a', 5000), 200)]);

        $this->assertNull($this->client->get('http://8.8.8.8/sitemap.xml'));
    }

    public function test_a_body_within_the_cap_is_returned_whole(): void
    {
        config(['crawler.max_response_bytes' => 1024]);

        Http::fake(['http://8.8.8.8/sitemap.xml' => Http::response(str_repeat('a', 1000), 200)]);

        $result = $this->client->get('http://8.8.8.8/sitemap.xml');

        $this->assertNotNull($result);
        $this->assertSame(1000, strlen($result['body']));
    }
}
