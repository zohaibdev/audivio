<?php

namespace App\Services\Crawler;

class UrlSecurityValidator
{
    /**
     * Validate a URL is safe to crawl: public scheme/port, resolves to a
     * public (non-private, non-reserved) IP address, no embedded
     * credentials, and a bounded query string. Throws on any violation.
     *
     * This must be called again on every redirect hop, not just the
     * initial URL — callers are responsible for re-validating each hop
     * they intend to follow.
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new SsrfValidationException("Malformed URL: {$url}");
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, config('crawler.allowed_schemes'), true)) {
            throw new SsrfValidationException("Disallowed URL scheme: {$scheme}");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfValidationException('URLs with embedded credentials are not allowed');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, config('crawler.allowed_ports'), true)) {
            throw new SsrfValidationException("Disallowed port: {$port}");
        }

        if (isset($parts['query']) && strlen($parts['query']) > 2048) {
            throw new SsrfValidationException('Query string exceeds maximum allowed length');
        }

        $host = $parts['host'];

        if (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            throw new SsrfValidationException('Blocked hostname: localhost');
        }

        $this->assertHostResolvesPublicly($host);
    }

    private function assertHostResolvesPublicly(string $host): void
    {
        // Host is already a literal IP address.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertIpIsPublic($host);

            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || count($records) === 0) {
            throw new SsrfValidationException("Could not resolve hostname: {$host}");
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            $this->assertIpIsPublic($ip);
        }
    }

    private function assertIpIsPublic(string $ip): void
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            throw new SsrfValidationException("Blocked private/internal address: {$ip}");
        }
    }
}
