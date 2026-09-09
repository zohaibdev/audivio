<?php

namespace App\Services\Crawler;

/**
 * Canonicalizes a user-supplied URL into the single form the rest of the
 * system stores, compares and crawls.
 *
 * This runs *before* UrlSecurityValidator, never instead of it. Its job is
 * to remove the ambiguity that makes validation unreliable — two spellings
 * of the same address (`HTTPS://Example.COM.:443/a#b` and
 * `https://example.com/a`) must not be able to disagree about whether they
 * passed a check. Anything that can't be reduced to an unambiguous
 * absolute http(s) URL is rejected here rather than normalized into
 * something that merely looks safe.
 */
class UrlNormalizer
{
    /**
     * @throws SsrfValidationException when the input cannot be reduced to
     *                                 an unambiguous absolute http(s) URL
     */
    public function normalize(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new SsrfValidationException('URL is empty');
        }

        if (strlen($url) > config('crawler.max_url_length')) {
            throw new SsrfValidationException('URL exceeds the maximum allowed length');
        }

        // Control characters (including the raw CR/LF and tab that
        // browsers silently strip from URLs) are rejected outright rather
        // than stripped: a URL that only becomes valid after removing
        // embedded newlines is a request-splitting attempt, not a typo.
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new SsrfValidationException('URL contains illegal control or whitespace characters');
        }

        $url = $this->applyDefaultScheme($url);

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme']) || empty($parts['host'])) {
            throw new SsrfValidationException("Malformed URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, config('crawler.allowed_schemes'), true)) {
            throw new SsrfValidationException("Disallowed URL scheme: {$scheme}");
        }

        // Credentials are dropped by neither normalization nor comparison —
        // they are a rejection, because `https://evil.com@127.0.0.1/` reads
        // as one host to a human and another to every URL parser.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfValidationException('URLs with embedded credentials are not allowed');
        }

        $host = $this->normalizeHost($parts['host']);

        $port = $parts['port'] ?? null;
        // A port that matches the scheme's default is noise, and keeping it
        // would make two spellings of the same origin compare unequal.
        if ($port !== null && (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        // The fragment is never sent to the server, so it can only create
        // spurious distinctions between otherwise-identical URLs.
        $authority = $port === null ? $host : "{$host}:{$port}";

        $normalized = "{$scheme}://{$authority}{$path}{$query}";

        if (strlen($normalized) > config('crawler.max_url_length')) {
            throw new SsrfValidationException('URL exceeds the maximum allowed length');
        }

        return $normalized;
    }

    /**
     * The normalized origin (`scheme://host[:port]`) — what robots.txt and
     * sitemap.xml URLs are built from, and what same-site comparisons run
     * against.
     */
    public function origin(string $url): string
    {
        $parts = parse_url($this->normalize($url));

        $authority = isset($parts['port'])
            ? "{$parts['host']}:{$parts['port']}"
            : $parts['host'];

        return "{$parts['scheme']}://{$authority}";
    }

    /**
     * The identity key two submissions of "the same website" collapse onto
     * (`websites.normalized_domain`).
     *
     * Deliberately more aggressive than `normalize()`: on top of the
     * lowercasing, punycode and root-dot handling that method already
     * does, this drops a leading `www.`, because `www.example.com` and
     * `example.com` are one website to a person deciding whether they have
     * already audited it. That makes it wrong for the crawler's same-site
     * checks — those compare against `websites.domain`, which stays
     * exactly as the site serves it — and right for deduplication, which
     * is the only thing this is for.
     *
     * @throws SsrfValidationException when the input has no usable host
     */
    public function normalizedDomain(string $url): string
    {
        $host = parse_url($this->normalize($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new SsrfValidationException("URL has no host: {$url}");
        }

        // An IPv6 literal keeps its brackets — stripping the `www.` prefix
        // is meaningless there, and the brackets are what make the value
        // reusable as a host.
        if (str_starts_with($host, '[')) {
            return $host;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Bare hostnames ("example.com", "example.com/pricing") are what people
     * actually type, so they get the https:// the omitted scheme implies.
     * Anything already carrying a scheme is left exactly as typed — quietly
     * prefixing `javascript:alert(1)` or `file:///etc/passwd` would turn a
     * value the scheme allowlist is meant to reject into one that sails
     * past it.
     */
    private function applyDefaultScheme(string $url): string
    {
        if (str_contains($url, '://')) {
            return $url;
        }

        // A colon before the first slash means the input already declares
        // *some* scheme (or an opaque one like `mailto:`/`javascript:`);
        // leave it alone so the allowlist gets to see it.
        $firstSlash = strpos($url, '/');
        $firstColon = strpos($url, ':');
        if ($firstColon !== false && ($firstSlash === false || $firstColon < $firstSlash)) {
            return $url;
        }

        return 'https://'.$url;
    }

    private function normalizeHost(string $host): string
    {
        // An IPv6 literal arrives from parse_url() still wrapped in the
        // brackets that separate it from the port; lowercase it but keep
        // the brackets so the reassembled URL stays parseable.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return strtolower($host);
        }

        $host = strtolower($host);

        // A trailing dot is the DNS root label. It resolves identically but
        // defeats every string comparison — `localhost.` would slip past a
        // literal "localhost" check, and `example.com.` would fail the
        // same-domain test against `example.com`.
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new SsrfValidationException('URL has no host');
        }

        // Internationalized domains are compared and resolved in their
        // ASCII form; without this, two spellings of the same host (Unicode
        // and punycode) would compare unequal.
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new SsrfValidationException("Host cannot be encoded as an ASCII domain: {$host}");
            }
            $host = $ascii;
        }

        if (strlen($host) > config('crawler.max_host_length')) {
            throw new SsrfValidationException('Host exceeds the maximum allowed length');
        }

        return $host;
    }
}
