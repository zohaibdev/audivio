<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Audit;
use App\Models\Screenshot;
use App\Services\Report\ReportAccessResolver;
use App\Services\Share\ShareTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a screenshot to someone entitled to see it (§48).
 *
 * Two independent gates, both required:
 *
 * 1. **The signature** (enforced by the `signed` middleware) proves the
 *    URL was issued by us and has not expired. Without it the id is a
 *    sequential integer anyone could walk.
 * 2. **The authorization check below** proves *this viewer* may see this
 *    audit. A signature alone is not enough, because report links get
 *    forwarded and a URL that outlives its context should stop working
 *    for a stranger rather than merely stop being secret.
 *
 * The second gate is the one that matters: it is what makes a leaked
 * URL useless rather than merely time-limited.
 */
class ScreenshotController extends Controller
{
    public function show(Request $request, Screenshot $screenshot): StreamedResponse|JsonResponse
    {
        $audit = $screenshot->audit;

        if ($audit === null) {
            return ApiResponse::error('Screenshot not found.', 404);
        }

        if (! $this->mayView($request, $audit)) {
            // Deliberately 404, not 403: confirming a screenshot exists
            // for an audit id is itself information about someone else's
            // account.
            return ApiResponse::error('Screenshot not found.', 404);
        }

        [$disk, $path] = $this->locate($screenshot);

        if ($disk === null) {
            return ApiResponse::error('Screenshot not found.', 404);
        }

        return Storage::disk($disk)->response(
            $path,
            basename($path),
            [
                'Content-Type' => 'image/png',
                // Private: this is somebody's site, behind a signed URL,
                // and it must not sit in a shared cache.
                'Cache-Control' => 'private, max-age=300',
            ],
        );
    }

    /**
     * Two ways to be entitled to this image, and no third (§50).
     *
     * Either you are the audit's owner, or you hold an unlocked share of
     * that same audit whose permissions include screenshots. The share
     * path re-checks three things every time — that the access token is
     * valid, that its share is still active, and that the share belongs
     * to *this* audit — because a screenshot URL is long-lived enough to
     * outlive the share it was rendered for.
     */
    private function mayView(Request $request, Audit $audit): bool
    {
        if (app(ReportAccessResolver::class)->hasFullAccess($audit, $request->user('sanctum'))) {
            return true;
        }

        $share = app(ShareTokenService::class)->shareFromAccessToken(
            $request->header('X-Share-Access') ?: $request->query('access_token'),
        );

        return $share !== null
            && $share->audit_id === $audit->id
            && $share->allow_screenshots;
    }

    /**
     * Which disk actually holds this file.
     *
     * Screenshots captured before §48 are on the `public` disk; new ones
     * are private. Both are readable here, so switching the disk did not
     * break every screenshot in every existing report.
     *
     * @return array{0: ?string, 1: string}
     */
    private function locate(Screenshot $screenshot): array
    {
        $path = ltrim($screenshot->storage_path, '/');

        foreach ([config('crawler.screenshot_disk'), 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return [$disk, $path];
            }
        }

        return [null, $path];
    }
}
