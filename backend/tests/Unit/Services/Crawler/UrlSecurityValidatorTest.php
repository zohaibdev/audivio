<?php

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlSecurityValidator;
use Tests\TestCase;

class UrlSecurityValidatorTest extends TestCase
{
    private function assertBlocked(string $url): void
    {
        $this->expectException(SsrfValidationException::class);
        (new UrlSecurityValidator)->assertSafe($url);
    }

    private function assertAllowed(string $url): void
    {
        (new UrlSecurityValidator)->assertSafe($url);
        $this->assertTrue(true); // reaching here without an exception is the assertion
    }

    // --- IP-literal targets: exercises assertIpIsPublic() directly, no DNS involved ---

    public function test_loopback_ipv4_is_blocked(): void
    {
        $this->assertBlocked('http://127.0.0.1/');
    }

    public function test_loopback_ipv6_is_blocked(): void
    {
        $this->assertBlocked('http://[::1]/');
    }

    public function test_private_class_a_is_blocked(): void
    {
        $this->assertBlocked('http://10.0.0.1/');
    }

    public function test_private_class_c_is_blocked(): void
    {
        $this->assertBlocked('http://192.168.1.1/');
    }

    public function test_private_class_b_is_blocked(): void
    {
        $this->assertBlocked('http://172.16.0.1/');
    }

    public function test_cloud_metadata_endpoint_is_blocked(): void
    {
        $this->assertBlocked('http://169.254.169.254/');
    }

    public function test_ipv6_unique_local_is_blocked(): void
    {
        $this->assertBlocked('http://[fc00::1]/');
    }

    public function test_public_ip_literal_is_allowed(): void
    {
        $this->assertAllowed('http://8.8.8.8/');
    }

    // --- Hostname string checks: short-circuit before DNS ---

    public function test_localhost_hostname_is_blocked(): void
    {
        $this->assertBlocked('http://localhost/');
    }

    public function test_localhost_case_insensitive_is_blocked(): void
    {
        $this->assertBlocked('http://LOCALHOST/');
    }

    public function test_subdomain_of_localhost_is_blocked(): void
    {
        $this->assertBlocked('http://foo.localhost/');
    }

    // --- Scheme / port / credentials / query length: also short-circuit before DNS ---

    public function test_ftp_scheme_is_blocked(): void
    {
        $this->assertBlocked('ftp://8.8.8.8/');
    }

    public function test_file_scheme_is_blocked(): void
    {
        $this->assertBlocked('file:///etc/passwd');
    }

    public function test_non_standard_port_is_blocked(): void
    {
        $this->assertBlocked('http://8.8.8.8:8080/');
    }

    public function test_standard_https_port_is_allowed(): void
    {
        $this->assertAllowed('https://8.8.8.8:443/');
    }

    public function test_embedded_credentials_are_blocked(): void
    {
        $this->assertBlocked('http://user:pass@8.8.8.8/');
    }

    public function test_oversized_query_string_is_blocked(): void
    {
        $this->assertBlocked('http://8.8.8.8/?q='.str_repeat('a', 3000));
    }

    public function test_malformed_url_is_blocked(): void
    {
        $this->assertBlocked('not a url at all');
    }

    // --- Real-world sanity check: a stable, always-public hostname resolves through DNS and passes ---

    public function test_real_public_hostname_resolves_and_is_allowed(): void
    {
        $this->assertAllowed('https://example.com/');
    }
}
