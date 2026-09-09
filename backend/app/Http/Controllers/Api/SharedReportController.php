<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlanFeature;
use App\Enums\ShareEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ReportShare;
use App\Services\Plan\EntitlementResolver;
use App\Services\Report\BrandingResolver;
use App\Services\Report\PageExplorerBuilder;
use App\Services\Report\ReportBuilder;
use App\Services\Report\ServiceReportBuilder;
use App\Services\Report\ServiceReportPdfService;
use App\Services\Report\ServiceReportVisibilityFilter;
use App\Services\Share\ShareAuditLogger;
use App\Services\Share\SharePermissionFilter;
use App\Services\Share\ShareTokenService;
use App\Services\Share\ShareUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a recipient of a share link can do (§50).
 *
 * Every response here carries `X-Robots-Tag: noindex, nofollow`. A shared
 * report is somebody's audit of their own site, sent to one person; it
 * has no business in a search index, and the URL being unguessable is no
 * protection at all once a crawler has followed it out of an email
 * preview or a chat unfurl.
 *
 * The order of checks never varies:
 *
 *   token → share exists and is active → password → permission
 *
 * and **nothing about the report is returned before the password step**.
 * `show()` deliberately returns only what a lock screen needs: that a
 * password is wanted, and whose branding to show. Not the domain, not the
 * score, not the audit date — a URL that leaks the audited domain before
 * authentication has already told a stranger who the owner's client is.
 */
class SharedReportController extends Controller
{
    public function __construct(
        private readonly ShareTokenService $tokens,
        private readonly ShareUnlockService $unlock,
        private readonly SharePermissionFilter $permissions,
        private readonly ShareAuditLogger $log,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * Does this link exist, and what does the lock screen need?
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $share = $this->activeShare($token);

        if ($share === null) {
            return $this->gone();
        }

        return $this->noindex(ApiResponse::success([
            'requires_password' => true,
            // Only the branding, so an agency's lock screen looks like
            // theirs. Falls back to ours, and reveals nothing about the
            // audited site either way.
            'branding' => collect(app(BrandingResolver::class)->forAudit($share->audit))
                ->only(['is_white_label', 'brand_name', 'logo_data_uri'])
                ->all(),
        ]));
    }

    /**
     * Verify the password and issue a short-lived access token.
     */
    public function unlock(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:200']]);

        $share = $this->activeShare($token);

        if ($share === null) {
            return $this->gone();
        }

        $result = $this->unlock->attempt($share, $validated['password'], $request);

        if (! $result['ok']) {
            if ($result['reason'] === 'locked') {
                return $this->noindex(ApiResponse::error(
                    'Too many incorrect attempts. Try again in '.ceil(($result['retry_after'] ?? 60) / 60).' minute(s).',
                    429,
                ));
            }

            // Identical message and status whatever went wrong, so the
            // response cannot be used to distinguish a wrong password
            // from anything else.
            return $this->noindex(ApiResponse::error('That password is not correct.', 422));
        }

        return $this->noindex(ApiResponse::success([
            'access_token' => $this->tokens->issueAccessToken($share),
            'expires_in_minutes' => (int) config('report_sharing.access_ttl_minutes'),
            'permissions' => [
                'allow_pdf' => $share->allow_pdf,
                'allow_screenshots' => $share->allow_screenshots,
                'allow_recommendations' => $share->allow_recommendations,
            ],
        ]));
    }

    /**
     * The report itself, filtered twice: by the owner's plan, then by the
     * share's permissions.
     */
    public function report(Request $request, string $token): JsonResponse
    {
        $share = $this->unlockedShare($request, $token);

        if ($share === null) {
            return $this->unauthorized();
        }

        $audit = $share->audit;

        if ($audit?->report === null) {
            return $this->gone();
        }

        // The *owner's* entitlements, not the guest tier: the recipient is
        // seeing the owner's report, so it is the owner's plan that
        // decides how much of it exists. The share's permissions then
        // narrow it further, never widen it.
        $entitlements = $this->entitlements->for($audit->user)
            ?? $this->entitlements->forOrDeny(null);

        $services = app(ServiceReportVisibilityFilter::class)->filterAll(
            app(ServiceReportBuilder::class)->buildAll($audit),
            $entitlements,
        );

        return $this->noindex(ApiResponse::success([
            'overview' => app(ReportBuilder::class)->build($audit)['overview'],
            'services' => $this->permissions->filterServices($services, $share),
            'is_full_access' => $entitlements->hasFullReportAccess(),
            'is_shared_view' => true,
            'permissions' => [
                'allow_pdf' => $share->allow_pdf,
                'allow_screenshots' => $share->allow_screenshots,
                'allow_recommendations' => $share->allow_recommendations,
            ],
        ]));
    }

    /**
     * The cross-service page explorer, when screenshots are permitted to
     * be part of it.
     */
    public function pages(Request $request, string $token): JsonResponse
    {
        $share = $this->unlockedShare($request, $token);

        if ($share === null) {
            return $this->unauthorized();
        }

        return $this->noindex(ApiResponse::success(
            $this->permissions->filterExplorerPages(
                app(PageExplorerBuilder::class)->build($share->audit),
                $share,
            ),
        ));
    }

    public function pdf(Request $request, string $token, string $service): Response|JsonResponse
    {
        $share = $this->unlockedShare($request, $token);

        if ($share === null) {
            return $this->unauthorized();
        }

        if (! $share->allow_pdf) {
            return $this->noindex(ApiResponse::error('This share does not include PDF downloads.', 403));
        }

        $audit = $share->audit;

        // The owner's plan still gates the feature. A share cannot grant
        // an export the account itself is not entitled to make.
        if (! $this->entitlements->for($audit->user)?->allows(PlanFeature::PdfExport)) {
            return $this->noindex(ApiResponse::error('PDF export is not available for this report.', 403));
        }

        $rendered = app(ServiceReportPdfService::class)->render(
            $audit,
            $service,
            $this->entitlements->for($audit->user),
        );

        if ($rendered === null) {
            return $this->noindex(ApiResponse::error('That service was not part of this audit.', 404));
        }

        $this->log->record(ShareEvent::PdfDownloaded, $share, $request, ['service' => $service]);

        return response($rendered['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$rendered['filename'].'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * A share that exists, is not revoked and has not expired.
     *
     * All three failures collapse into one null. A recipient who can tell
     * "no such link" from "revoked" learns whether they had the right URL,
     * which is the one thing token secrecy is supposed to withhold.
     */
    private function activeShare(string $token): ?ReportShare
    {
        $share = $this->tokens->findByToken($token);

        return $share !== null && $share->isActive() ? $share : null;
    }

    /**
     * The share behind a valid access token, re-checked against the URL's
     * own token.
     *
     * Binding the two matters: without it, an access token minted for one
     * share would unlock any other share whose URL the holder happened to
     * know — a straightforward IDOR through a credential that looks
     * legitimate.
     */
    private function unlockedShare(Request $request, string $token): ?ReportShare
    {
        $unlocked = $this->tokens->shareFromAccessToken(
            $request->header('X-Share-Access') ?: $request->query('access_token'),
        );

        if ($unlocked === null) {
            return null;
        }

        $fromUrl = $this->activeShare($token);

        return $fromUrl !== null && $fromUrl->id === $unlocked->id ? $unlocked : null;
    }

    private function gone(): JsonResponse
    {
        return $this->noindex(ApiResponse::error('This share link is no longer available.', 404));
    }

    private function unauthorized(): JsonResponse
    {
        return $this->noindex(ApiResponse::error('This report is locked. Enter the password to continue.', 401));
    }

    private function noindex(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }
}
