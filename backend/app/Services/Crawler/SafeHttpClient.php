<?php

namespace App\Services\Crawler;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one way this application makes an HTTP request to a user-supplied
 * address from PHP (robots.txt and sitemap.xml fetches).
 *
 * Validating a URL and then handing it to a client that follows redirects
 * on its own is not SSRF protection: the check applies to the first hop,
 * and the redirect chain is where the request actually ends up. So
 * redirects are turned *off* at the transport and followed here by hand,
 * with UrlSecurityValidator run again on every hop. A response body is
 * also read under a hard byte cap, because a validated public host can
 * still answer with an endless stream.
 */
class SafeHttpClient
{
    public function __construct(
        private readonly UrlSecurityValidator $validator,
    ) {}

    /**
     * Fetch a URL, validating it and every redirect hop.
     *
     * Returns null — rather than throwing — when the redirect budget ran
     * out, the response was unsuccessful, or the body exceeded the size
     * cap. Those are ordinary "this site has nothing usable for us"
     * outcomes for an optional fetch. An unsafe address is different: it
     * throws, so a caller can never mistake a blocked SSRF attempt for a
     * missing robots.txt.
     *
     * @return array{response: Response, url: string, body: string}|null
     *
     * @throws SsrfValidationException when the initial URL or any redirect
     *                                 hop points somewhere it must not
     */
    public function get(string $url): ?array
    {
        $maxRedirects = (int) config('crawler.max_redirects');
        $maxBytes = (int) config('crawler.max_response_bytes');

        $this->validator->assertSafe($url);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $response = Http::withUserAgent(config('crawler.user_agent'))
                ->timeout(config('crawler.request_timeout'))
                ->withOptions([
                    // The whole point: the transport must not follow
                    // anything on its own, or the hop below never runs.
                    'allow_redirects' => false,
                ])
                ->get($url);

            if (! $response->redirect()) {
                if (! $response->successful()) {
                    return null;
                }

                $body = $this->readBounded($response, $maxBytes);

                return $body === null ? null : ['response' => $response, 'url' => $url, 'body' => $body];
            }

            $location = $response->header('Location');

            if ($location === '' || $location === null) {
                return null;
            }

            $url = $this->resolveLocation($url, $location);

            // Re-validated from scratch, not diffed against the previous
            // hop: a redirect may legitimately cross hosts, so the only
            // question that matters is whether the *new* target is safe.
            $this->validator->assertSafe($url);
        }

        // Ran out of hops. A chain this long is either a loop or an
        // attempt to outlast the checks; either way, stop.
        return null;
    }

    /**
     * A Location header may be relative; resolve it against the URL that
     * produced it so the security check sees the absolute target the
     * client would actually request.
     */
    private function resolveLocation(string $baseUrl, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $origin = "{$scheme}://{$host}{$port}";

        // Scheme-relative: //other-host/path
        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = $parts['path'] ?? '/';
        $directory = substr($basePath, 0, strrpos($basePath, '/') + 1) ?: '/';

        return $origin.$directory.$location;
    }

    /**
     * Read at most $maxBytes of the body, returning null if the response is
     * larger. Content-Length is checked first because it lets an oversized
     * response be rejected without reading it at all, but it is not
     * trusted as the only check — it is absent on chunked responses and
     * describes the compressed size on encoded ones.
     */
    private function readBounded(Response $response, int $maxBytes): ?string
    {
        $declared = (int) ($response->header('Content-Length') ?: 0);

        if ($declared > $maxBytes) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(8192);

            if (strlen($body) > $maxBytes) {
                return null;
            }
        }

        return $body;
    }
}
