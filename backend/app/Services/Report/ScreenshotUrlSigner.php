<?php

namespace App\Services\Report;

use App\Models\Screenshot;
use Illuminate\Support\Facades\URL;

/**
 * Signed, expiring URLs for screenshots (§48).
 *
 * Screenshots used to live on Laravel's `public` disk and be served
 * straight from `/storage/screenshots/{auditId}/{page}/desktop.png`.
 * That path is guessable — the audit id is a sequential integer — so
 * anyone could walk it and pull back rendered captures of other people's
 * sites, including pages that are not linked publicly, behind a staging
 * password the crawler was given, or simply not meant to be advertised.
 * Nothing about the file itself said who it belonged to.
 *
 * Every screenshot URL is now signed and time-limited, and the route
 * behind it re-checks that the viewer may see this audit's report at all.
 * The signature stops the URL being forged; the authorization check stops
 * a *valid* URL being useful to someone it was not issued for, which
 * matters because report links get forwarded.
 */
class ScreenshotUrlSigner
{
    public function urlFor(?Screenshot $screenshot): ?string
    {
        if ($screenshot === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'screenshots.show',
            now()->addMinutes((int) config('crawler.screenshot_url_ttl_minutes')),
            ['screenshot' => $screenshot->id],
        );
    }

    /**
     * Both viewports for a page, keyed for the report payloads.
     *
     * @param  iterable<Screenshot>  $screenshots
     * @return array{desktop: string|null, mobile: string|null}
     */
    public function urlsFor(iterable $screenshots): array
    {
        $byViewport = collect($screenshots)->keyBy(fn (Screenshot $s) => $s->viewport->value);

        return [
            'desktop' => $this->urlFor($byViewport->get('desktop')),
            'mobile' => $this->urlFor($byViewport->get('mobile')),
        ];
    }
}
