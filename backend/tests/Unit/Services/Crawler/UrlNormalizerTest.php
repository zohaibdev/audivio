<?php

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlNormalizer;
use Tests\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UrlNormalizer;
    }

    private function assertRejected(string $url): void
    {
        $this->expectException(SsrfValidationException::class);
        $this->normalizer->normalize($url);
    }

    public function test_scheme_and_host_are_lowercased(): void
    {
        $this->assertSame('https://example.com/', $this->normalizer->normalize('HTTPS://Example.COM'));
    }

    public function test_bare_host_gets_the_implied_https_scheme(): void
    {
        $this->assertSame('https://example.com/', $this->normalizer->normalize('example.com'));
    }

    public function test_bare_host_with_path_gets_the_implied_scheme(): void
    {
        $this->assertSame('https://example.com/pricing', $this->normalizer->normalize('example.com/pricing'));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('https://example.com/', $this->normalizer->normalize('  https://example.com  '));
    }

    public function test_empty_path_becomes_root(): void
    {
        $this->assertSame('https://example.com/', $this->normalizer->normalize('https://example.com'));
    }

    public function test_fragment_is_dropped(): void
    {
        $this->assertSame('https://example.com/a', $this->normalizer->normalize('https://example.com/a#section'));
    }

    public function test_query_string_is_preserved(): void
    {
        $this->assertSame('https://example.com/a?b=c', $this->normalizer->normalize('https://example.com/a?b=c#x'));
    }

    public function test_default_https_port_is_dropped(): void
    {
        $this->assertSame('https://example.com/', $this->normalizer->normalize('https://example.com:443/'));
    }

    public function test_default_http_port_is_dropped(): void
    {
        $this->assertSame('http://example.com/', $this->normalizer->normalize('http://example.com:80/'));
    }

    public function test_non_default_port_is_kept_for_the_validator_to_reject(): void
    {
        // Normalization does not decide policy — the port allowlist lives
        // in UrlSecurityValidator, so a non-default port must survive here
        // rather than be silently rewritten into an allowed one.
        $this->assertSame('http://example.com:8080/', $this->normalizer->normalize('http://example.com:8080/'));
    }

    public function test_dns_root_trailing_dot_is_stripped_from_the_host(): void
    {
        $this->assertSame('https://example.com/', $this->normalizer->normalize('https://example.com./'));
    }

    public function test_unicode_host_is_converted_to_punycode(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('ext-intl is not installed');
        }

        $this->assertSame('https://xn--bcher-kva.example/', $this->normalizer->normalize('https://bücher.example'));
    }

    public function test_ipv6_literal_keeps_its_brackets(): void
    {
        $this->assertSame('https://[2606:4700::1111]/', $this->normalizer->normalize('https://[2606:4700::1111]/'));
    }

    // --- Rejections ---

    public function test_javascript_scheme_is_rejected_rather_than_prefixed(): void
    {
        $this->assertRejected('javascript:alert(1)');
    }

    public function test_data_scheme_is_rejected(): void
    {
        $this->assertRejected('data:text/html,<h1>x</h1>');
    }

    public function test_file_scheme_is_rejected(): void
    {
        $this->assertRejected('file:///etc/passwd');
    }

    public function test_mailto_scheme_is_rejected(): void
    {
        $this->assertRejected('mailto:someone@example.com');
    }

    public function test_embedded_credentials_are_rejected(): void
    {
        $this->assertRejected('https://user:pass@example.com/');
    }

    public function test_embedded_newline_is_rejected(): void
    {
        $this->assertRejected("https://example.com/\r\nX-Injected: 1");
    }

    public function test_embedded_tab_is_rejected(): void
    {
        $this->assertRejected("https://exa\tmple.com/");
    }

    public function test_empty_input_is_rejected(): void
    {
        $this->assertRejected('   ');
    }

    public function test_overlong_url_is_rejected(): void
    {
        $this->assertRejected('https://example.com/'.str_repeat('a', 4000));
    }

    public function test_url_without_a_host_is_rejected(): void
    {
        $this->assertRejected('https:///just-a-path');
    }

    // --- origin() ---

    public function test_origin_strips_path_query_and_fragment(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->origin('https://example.com/a/b?c=d#e'));
    }

    public function test_origin_of_a_bare_host(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->origin('example.com'));
    }
}
