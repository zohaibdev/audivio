<?php

namespace App\Services\Crawler;

class UrlSecurityValidator
{
    /**
     * Validate a URL is safe to crawl: public scheme/port, a well-formed
     * host that resolves only to public (non-private, non-reserved) IP
     * addresses, no embedded credentials, and bounded lengths. Throws on
     * any violation.
     *
     * This must be called again on every redirect hop, not just the
     * initial URL — SafeHttpClient does that for the PHP-side fetches, and
     * crawler/lib/ssrf.mjs does the equivalent for Playwright navigation.
     *
     * @return string[] every IP address the host resolved to, in the order
     *                  the resolver returned them.
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new SsrfValidationException("Malformed URL: {$url}");
        }

        if (strlen($url) > config('crawler.max_url_length')) {
            throw new SsrfValidationException('URL exceeds the maximum allowed length');
        }

        // Raw control characters and whitespace never legitimately survive
        // into a URL that reaches this point; if one has, something built
        // the URL by concatenation and a CR/LF could split a request.
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new SsrfValidationException('URL contains illegal control or whitespace characters');
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

        if (isset($parts['query']) && strlen($parts['query']) > config('crawler.max_query_length')) {
            throw new SsrfValidationException('Query string exceeds maximum allowed length');
        }

        $host = $this->canonicalHost($parts['host']);

        if (strlen($host) > config('crawler.max_host_length')) {
            throw new SsrfValidationException('Host exceeds the maximum allowed length');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new SsrfValidationException('Blocked hostname: localhost');
        }

        return $this->assertHostResolvesPublicly($host);
    }

    /**
     * Strip the parts of a host that are display-only, so every later check
     * runs against one spelling. Mirrors UrlNormalizer::normalizeHost() —
     * repeated here rather than delegated because assertSafe() is also
     * called on hosts that never went through normalization (redirect
     * targets, sitemap entries, links found on a crawled page).
     */
    private function canonicalHost(string $host): string
    {
        $host = strtolower($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return rtrim($host, '.');
    }

    /**
     * @return string[]
     */
    private function assertHostResolvesPublicly(string $host): array
    {
        // Host is already a literal IP address.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertIpIsPublic($host);

            return [$host];
        }

        $this->assertIsWellFormedDomain($host);

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || count($records) === 0) {
            throw new SsrfValidationException("Could not resolve hostname: {$host}");
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            $this->assertIpIsPublic($ip);
            $ips[] = $ip;
        }

        // Records existed but carried no address (e.g. only CNAME rows came
        // back). Nothing was checked, so nothing may be trusted.
        if ($ips === []) {
            throw new SsrfValidationException("Could not resolve hostname to an address: {$host}");
        }

        return $ips;
    }

    /**
     * Reject hosts that are neither a valid IP literal nor a valid DNS
     * name. Two classes of input land here:
     *
     * - Alternate integer spellings of an IP: 2130706433, 0177.0.0.1,
     *   0x7f.0.0.1. filter_var() rejects all three as IPs, but the
     *   resolvers underneath curl and Chromium happily read them as
     *   127.0.0.1 — so a host that merely *fails* the IP check must not be
     *   waved through as a domain name. A real TLD is never numeric, so
     *   requiring a non-numeric last label catches every such form.
     * - Injection payloads (shell metacharacters, SQL quotes, angle
     *   brackets). These can't be domains, and rejecting them here means
     *   they never reach a resolver, a subprocess argument, or a log line.
     */
    private function assertIsWellFormedDomain(string $host): void
    {
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (! preg_match('/^'.$label.'(?:\.'.$label.')*$/', $host)) {
            throw new SsrfValidationException("Invalid hostname: {$host}");
        }

        $labels = explode('.', $host);
        $lastLabel = end($labels);

        if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/', $lastLabel)) {
            throw new SsrfValidationException("Numeric hostname is not a valid domain: {$host}");
        }
    }

    private function assertIpIsPublic(string $ip): void
    {
        // An IPv4-mapped IPv6 address (::ffff:127.0.0.1) carries a real
        // IPv4 destination inside it; check that destination against the
        // IPv4 rules rather than only the IPv6 ones.
        $mapped = $this->mappedIpv4($ip);
        if ($mapped !== null) {
            $this->assertIpIsPublic($mapped);

            return;
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            throw new SsrfValidationException("Blocked private/internal address: {$ip}");
        }

        // PHP's flags cover the well-known private and reserved ranges but
        // miss several that are still internal in practice (see the
        // blocked_cidrs comment in config/crawler.php).
        foreach (config('crawler.blocked_cidrs') as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                throw new SsrfValidationException("Blocked private/internal address: {$ip}");
            }
        }
    }

    private function mappedIpv4(string $ip): ?string
    {
        if (! str_contains($ip, ':') || ! str_contains($ip, '.')) {
            return null;
        }

        $candidate = substr($ip, strrpos($ip, ':') + 1);

        return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $candidate : null;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr);

        $ipBytes = @inet_pton($ip);
        $subnetBytes = @inet_pton($subnet);

        // Different address families can't overlap, so an IPv4 address is
        // never inside an IPv6 CIDR and vice versa.
        if ($ipBytes === false || $subnetBytes === false || strlen($ipBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $prefix = (int) $prefix;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($ipBytes, $subnetBytes, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($subnetBytes[$wholeBytes]) & $mask);
    }
}
