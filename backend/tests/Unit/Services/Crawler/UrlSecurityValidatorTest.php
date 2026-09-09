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

    public function test_gcp_metadata_link_local_is_blocked(): void
    {
        $this->assertBlocked('http://169.254.169.254/computeMetadata/v1/');
    }

    public function test_ipv6_unique_local_is_blocked(): void
    {
        $this->assertBlocked('http://[fc00::1]/');
    }

    public function test_ipv6_link_local_is_blocked(): void
    {
        $this->assertBlocked('http://[fe80::1]/');
    }

    public function test_ipv6_unspecified_is_blocked(): void
    {
        $this->assertBlocked('http://[::]/');
    }

    public function test_public_ip_literal_is_allowed(): void
    {
        $this->assertAllowed('http://8.8.8.8/');
    }

    // --- Ranges PHP's own FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE does not cover ---

    public function test_carrier_grade_nat_range_is_blocked(): void
    {
        $this->assertBlocked('http://100.64.0.1/');
    }

    public function test_ietf_protocol_assignments_range_is_blocked(): void
    {
        $this->assertBlocked('http://192.0.0.1/');
    }

    public function test_benchmark_range_is_blocked(): void
    {
        $this->assertBlocked('http://198.18.0.1/');
    }

    public function test_multicast_range_is_blocked(): void
    {
        $this->assertBlocked('http://224.0.0.1/');
    }

    public function test_nat64_prefix_is_blocked(): void
    {
        // 64:ff9b::/96 embeds an arbitrary IPv4 destination — here, the AWS
        // metadata endpoint — inside an otherwise public-looking IPv6 address.
        $this->assertBlocked('http://[64:ff9b::a9fe:a9fe]/');
    }

    public function test_ipv4_mapped_ipv6_loopback_is_blocked(): void
    {
        $this->assertBlocked('http://[::ffff:127.0.0.1]/');
    }

    public function test_ipv4_mapped_ipv6_private_range_is_blocked(): void
    {
        $this->assertBlocked('http://[::ffff:192.168.1.1]/');
    }

    public function test_ipv4_mapped_ipv6_in_hex_form_is_blocked(): void
    {
        // The same address as ::ffff:127.0.0.1, written without the dotted
        // quad — it must not be reachable just because it looks different.
        $this->assertBlocked('http://[::ffff:7f00:1]/');
    }

    public function test_addresses_just_outside_blocked_ranges_are_allowed(): void
    {
        $this->assertAllowed('http://100.63.255.255/');
        $this->assertAllowed('http://198.20.0.1/');
        $this->assertAllowed('http://172.32.0.1/');
    }

    // --- Alternate integer spellings of an IP address ---

    public function test_decimal_integer_form_of_loopback_is_blocked(): void
    {
        // 2130706433 == 127.0.0.1 to Chromium's and curl's resolvers.
        $this->assertBlocked('http://2130706433/');
    }

    public function test_octal_form_of_loopback_is_blocked(): void
    {
        $this->assertBlocked('http://0177.0.0.1/');
    }

    public function test_hex_form_of_loopback_is_blocked(): void
    {
        $this->assertBlocked('http://0x7f.0.0.1/');
    }

    public function test_short_dotted_form_of_loopback_is_blocked(): void
    {
        $this->assertBlocked('http://127.1/');
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

    public function test_localhost_with_trailing_dns_root_dot_is_blocked(): void
    {
        // "localhost." resolves identically but is a different string, so a
        // literal comparison that skips canonicalization would let it past.
        $this->assertBlocked('http://localhost./');
    }

    // --- Scheme / port / credentials / lengths: also short-circuit before DNS ---

    public function test_ftp_scheme_is_blocked(): void
    {
        $this->assertBlocked('ftp://8.8.8.8/');
    }

    public function test_file_scheme_is_blocked(): void
    {
        $this->assertBlocked('file:///etc/passwd');
    }

    public function test_javascript_scheme_is_blocked(): void
    {
        $this->assertBlocked('javascript:alert(1)');
    }

    public function test_data_scheme_is_blocked(): void
    {
        $this->assertBlocked('data:text/html,<script>alert(1)</script>');
    }

    public function test_gopher_scheme_is_blocked(): void
    {
        $this->assertBlocked('gopher://8.8.8.8:70/_test');
    }

    public function test_non_standard_port_is_blocked(): void
    {
        $this->assertBlocked('http://8.8.8.8:8080/');
    }

    public function test_redis_port_is_blocked(): void
    {
        $this->assertBlocked('http://8.8.8.8:6379/');
    }

    public function test_standard_https_port_is_allowed(): void
    {
        $this->assertAllowed('https://8.8.8.8:443/');
    }

    public function test_embedded_credentials_are_blocked(): void
    {
        $this->assertBlocked('http://user:pass@8.8.8.8/');
    }

    public function test_credentials_disguising_an_internal_host_are_blocked(): void
    {
        // Reads as example.com to a human; every URL parser sees 127.0.0.1.
        $this->assertBlocked('http://example.com@127.0.0.1/');
    }

    public function test_oversized_query_string_is_blocked(): void
    {
        $this->assertBlocked('http://8.8.8.8/?q='.str_repeat('a', 3000));
    }

    public function test_oversized_url_is_blocked(): void
    {
        $this->assertBlocked('http://8.8.8.8/'.str_repeat('a', 4000));
    }

    public function test_malformed_url_is_blocked(): void
    {
        $this->assertBlocked('not a url at all');
    }

    // --- Injection payloads in the host position ---

    public function test_shell_metacharacters_in_host_are_blocked(): void
    {
        $this->assertBlocked('http://example.com;curl%20evil.test/');
    }

    public function test_shell_command_substitution_in_host_is_blocked(): void
    {
        $this->assertBlocked('http://$(whoami).example.com/');
    }

    public function test_sql_injection_payload_in_host_is_blocked(): void
    {
        $this->assertBlocked("http://example.com'%20OR%201=1--/");
    }

    public function test_xss_payload_in_host_is_blocked(): void
    {
        $this->assertBlocked('http://<script>alert(1)</script>/');
    }

    public function test_crlf_in_url_is_blocked(): void
    {
        // A raw CR/LF is how a URL built by concatenation turns into two
        // requests; browsers strip these silently, so they must be rejected
        // rather than normalized away.
        $this->assertBlocked("http://8.8.8.8/\r\nX-Injected: 1");
    }

    public function test_null_byte_in_url_is_blocked(): void
    {
        $this->assertBlocked("http://8.8.8.8/\0");
    }

    // --- Real-world sanity check: a stable, always-public hostname resolves through DNS and passes ---

    public function test_real_public_hostname_resolves_and_is_allowed(): void
    {
        $this->assertAllowed('https://example.com/');
    }

    public function test_resolved_addresses_are_returned_for_the_caller_to_pin(): void
    {
        $ips = (new UrlSecurityValidator)->assertSafe('http://8.8.8.8/');

        $this->assertSame(['8.8.8.8'], $ips);
    }
}
