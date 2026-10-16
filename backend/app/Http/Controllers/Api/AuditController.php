<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAuditRequest;
use App\Http\Responses\ApiResponse;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\UsageLog;
use App\Models\Website;
use App\Services\Audit\ActiveAuditLimiter;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\AuditProgressCalculator;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Crawler\UrlSecurityValidator;
use App\Services\Plan\EntitlementResolver;
use App\Services\Plan\Entitlements;
use App\Services\Plan\PlanLimitExceeded;
use App\Services\Report\AuditComparison;
use App\Services\Report\PageExplorerBuilder;
use App\Services\Report\ReportAccessResolver;
use App\Services\Report\ReportBuilder;
use App\Services\Report\ReportPdfService;
use App\Services\Report\ReportVisibilityFilter;
use App\Services\Report\ServiceReportBuilder;
use App\Services\Report\ServiceReportPdfService;
use App\Services\Report\ServiceReportVisibilityFilter;
use App\Services\User\UsageLimitService;
use App\Services\Website\WebsiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuditController extends Controller
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
        private readonly UrlSecurityValidator $urlValidator,
        private readonly UsageLimitService $usageLimits,
        private readonly WebsiteResolver $websites,
        private readonly ActiveAuditLimiter $activeAudits,
        private readonly AuditLifecycle $lifecycle,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * The current user's own audit history — requires auth:sanctum
     * (unlike store()/show(), which are anonymous-friendly), since an
     * anonymous visitor has no persistent identity to list audits
     * against.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()->audits()
                ->with(['website', 'scores'])
                ->orderByDesc('id')
                ->paginate(25),
        );
    }

    public function store(CreateAuditRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        // Normalize first, then validate the normalized form — and store
        // and crawl that same string. Validating the raw input and then
        // storing something else would mean the URL that passed the check
        // is not the URL that gets fetched, which is exactly the gap every
        // SSRF filter bypass lives in.
        //
        // The request rules already applied a scheme allowlist and a length
        // cap; both are re-applied here rather than assumed, since this
        // path must hold for any caller, not only one that came through
        // form-request validation.
        try {
            $url = $this->urlNormalizer->normalize($request->string('url')->toString());
            $this->urlValidator->assertSafe($url);
        } catch (SsrfValidationException $e) {
            return ApiResponse::error("This URL cannot be audited: {$e->getMessage()}", 422);
        }

        // Says *which* allowance ran out — daily, monthly, or the
        // anonymous lifetime one — rather than a single generic message
        // that leaves the user guessing what to do about it (section 40).
        $refusal = $this->usageLimits->auditRefusalReason($user, $request->ip());

        if ($refusal !== null) {
            return ApiResponse::error($refusal->getMessage(), 429, ['limit' => $refusal->limit]);
        }

        // Resolved, not created: auditing a site the user has audited
        // before reuses that website record, so repeated audits accumulate
        // as history under one card instead of spawning a new one each
        // time. The domain comes from the normalized URL, so the crawler's
        // same-domain comparisons against it are plain string equality.
        try {
            $website = $this->websites->resolve($user, $url);
        } catch (PlanLimitExceeded $e) {
            // 403, not 429: the website cap is not a rate to wait out, it
            // is an allowance that only changes by removing a site or
            // upgrading.
            return ApiResponse::error($e->getMessage(), 403, ['limit' => $e->limit]);
        }

        // The concurrency check and the insert happen together under one
        // lock. Checking first and creating afterwards is the race the
        // spec calls out: two simultaneous requests both see a free slot.
        // The callback runs only if there is genuinely room (§39).
        $audit = $this->activeAudits->withSlot($user, $request->ip(), function () use ($user, $request, $website) {
            $audit = Audit::create([
                'website_id' => $website->id,
                'user_id' => $user?->id,
                'status' => AuditStatus::Queued,
                'max_pages' => $this->usageLimits->maxPagesFor($user),
                'progress_at' => now(),
            ]);

            $this->usageLimits->recordAuditCreated($user, $request->ip(), $website, $audit);

            return $audit;
        });

        if ($audit === null) {
            $limit = $this->activeAudits->limitFor($user);

            return ApiResponse::error(
                $limit === 1
                    ? 'You already have an audit running. Wait for it to finish, or cancel it, before starting another.'
                    : "You already have {$limit} audits running, which is the most your plan allows at once.",
                429,
            );
        }

        CrawlWebsiteJob::dispatch($audit);

        // Report the status as of creation (Queued), not $audit->status:
        // with a synchronous queue driver (as in tests), dispatch() above
        // runs the whole job inline and updates the DB row before this
        // line runs, but this PHP object's in-memory status was never
        // refreshed — reading it back would report a queue-driver-
        // dependent value instead of the one true fact at response time:
        // the audit was just accepted and queued.
        return ApiResponse::success(['audit_id' => $audit->id, 'status' => AuditStatus::Queued->value], status: 202);
    }

    public function show(Request $request, Audit $audit): JsonResponse
    {
        // A registered audit's progress is visible only to its own owner
        // (or anonymously, matching the flow that creates it) — not to a
        // different logged-in user who happens to know/guess the ID.
        // Anonymous audits have no ownership concept to enforce (see
        // ReportAccessResolver) and stay visible to anyone who knows the
        // ID; that gap is accepted (§28), this closes the narrower one
        // where a *different account* could probe another user's audits.
        if ($audit->user_id !== null && $request->user('sanctum')?->id !== $audit->user_id) {
            return ApiResponse::error('Audit not found.', 404);
        }

        return ApiResponse::success([
            'audit_id' => $audit->id,
            'status' => $audit->status->value,
            'status_label' => $audit->status->label(),
            'is_active' => $audit->status->isActive(),
            'is_cancellable' => $audit->status->isCancellable(),
            'pages_crawled' => $audit->pages_crawled,
            'max_pages' => $audit->max_pages,
            'error_message' => $audit->error_message,
            'failed_stage' => $audit->failed_stage,
            // What is actually happening, and how much longer (§51).
            // Every figure here is measured; where nothing has been
            // measured yet the estimate reports itself as unavailable
            // rather than inventing one.
            'progress' => app(AuditProgressCalculator::class)->forAudit($audit),
        ]);
    }

    /**
     * Cancel a running audit, releasing its concurrent slot.
     *
     * Cancellation is cooperative: a job already executing is not killed,
     * it finds the terminal status at its next checkpoint and stops. So
     * the slot frees immediately, while any in-flight page fetch may take
     * a few more seconds to wind down — which is why the response says
     * "cancelled", not "stopped".
     */
    public function cancel(Request $request, Audit $audit): JsonResponse
    {
        // Stricter than show(), deliberately. Letting anyone who knows an
        // anonymous audit's ID *read* it is an accepted gap (§28), because
        // reading changes nothing. Cancelling destroys a running audit, so
        // an anonymous one is restricted to the IP that created it —
        // otherwise anyone walking the ID space could kill every guest's
        // audit. A registered audit belongs to its owner alone. Both
        // answer 404, never 403, so neither confirms an ID exists.
        if (! $this->canCancel($request, $audit)) {
            return ApiResponse::error('Audit not found.', 404);
        }

        if (! $audit->status->isCancellable()) {
            return ApiResponse::error(
                "This audit has already {$audit->status->label()}; there is nothing to cancel.",
                422,
            );
        }

        // A conditional update, so two cancel requests (or a cancel racing
        // the audit's own completion) cannot both win.
        if (! $this->lifecycle->markCancelled($audit)) {
            return ApiResponse::error('This audit finished before it could be cancelled.', 422);
        }

        return ApiResponse::success([
            'audit_id' => $audit->id,
            'status' => $audit->fresh()->status->value,
        ], message: 'Audit cancelled.');
    }

    /**
     * Which entitlements govern how much of $audit this request may read.
     *
     * The audit's **owner** is filtered by their own plan — that is what
     * makes a free-tier subscriber see a partial report of their own audit
     * and a paid one see all of it. **Anyone else** is filtered by the
     * guest tier, whatever plan they are on: a generous plan describes what
     * you get for your own audits, and must never become a key to someone
     * else's (§40).
     */
    /**
     * What changed since the previous completed audit of this site (§52).
     *
     * Owner-only, like the page explorer: a comparison names the pages
     * that were and were not crawled, which is exactly the detail the
     * free view withholds.
     */
    public function comparison(Request $request, Audit $audit): JsonResponse
    {
        if (! app(ReportAccessResolver::class)->hasFullAccess($audit, $request->user('sanctum'))) {
            return ApiResponse::error("Sign in as this audit's owner to compare audits.", 403);
        }

        $comparison = app(AuditComparison::class);
        $previousId = $request->integer('previous');

        $previous = $previousId > 0
            // Scoped to the same website and to completed runs, for the
            // same reasons previousFor() applies: comparing across sites
            // is meaningless, an arbitrary id would let one audit's owner
            // read another's page list, and a failed run has no scores to
            // compare — every service would report as incomparable and
            // every finding as new.
            ? Audit::where('website_id', $audit->website_id)
                ->where('id', $previousId)
                ->where('id', '!=', $audit->id)
                ->where('status', AuditStatus::Completed->value)
                ->first()
            : $comparison->previousFor($audit);

        if ($previousId > 0 && $previous === null) {
            // Said rather than silently substituted. Quietly comparing
            // against a different audit than the one asked for is exactly
            // the kind of unannounced substitution this endpoint exists
            // to avoid.
            return ApiResponse::error(
                'That is not a completed audit of this website, so it cannot be used as the comparison point.',
                404,
            );
        }

        if ($previous === null) {
            return ApiResponse::success([
                'is_available' => false,
                'reason' => 'This is the first completed audit for this website, so there is nothing to compare it against yet.',
                'comparison' => null,
            ]);
        }

        return ApiResponse::success([
            'is_available' => true,
            'reason' => null,
            'comparison' => $comparison->compare($previous, $audit),
        ]);
    }

    private function viewerEntitlements(Request $request, Audit $audit): Entitlements
    {
        $viewer = $request->user('sanctum');
        $guest = $this->entitlements->forOrDeny(null);

        if (! app(ReportAccessResolver::class)->hasFullAccess($audit, $viewer)) {
            return $guest;
        }

        // Falls back to the guest tier rather than denying outright when
        // the owner has no resolvable plan (a subscription row missing —
        // the anomaly in §40). An owner must never see *less* of their own
        // audit than a passing stranger would.
        return $this->entitlements->for($viewer) ?? $guest;
    }

    /**
     * A registered audit is cancellable only by its owner; an anonymous
     * one only from the IP that created it, recorded in `usage_logs`
     * (audits carry no IP of their own).
     *
     * IP matching is weak identity — it breaks behind a changing mobile
     * IP and is spoofable given a trusted-proxy misconfiguration — but it
     * is the only signal an anonymous audit has, and it turns "anyone can
     * cancel anyone's guest audit" into "the same network can". A guest
     * whose IP changed simply waits for the audit to finish.
     */
    private function canCancel(Request $request, Audit $audit): bool
    {
        if ($audit->user_id !== null) {
            return $request->user('sanctum')?->id === $audit->user_id;
        }

        return UsageLog::where('audit_id', $audit->id)
            ->where('ip_address', $request->ip())
            ->exists();
    }

    public function report(Request $request, Audit $audit): JsonResponse
    {
        $report = $audit->report;

        if ($report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        $content = app(ReportVisibilityFilter::class)
            ->filter($report->content, $this->viewerEntitlements($request, $audit));

        return ApiResponse::success($content);
    }

    /**
     * The per-service reports (§35) — one independently viewable report
     * per audit service, built from the same normalized model the PDFs
     * render from. Optional auth, same visibility split as report().
     */
    public function serviceReports(Request $request, Audit $audit): JsonResponse
    {
        if ($audit->report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        $entitlements = $this->viewerEntitlements($request, $audit);
        $reports = app(ServiceReportBuilder::class)->buildAll($audit);

        return ApiResponse::success([
            'overview' => app(ReportBuilder::class)->build($audit)['overview'],
            'services' => app(ServiceReportVisibilityFilter::class)->filterAll($reports, $entitlements),
            'is_full_access' => $entitlements->hasFullReportAccess(),
        ]);
    }

    public function serviceReport(Request $request, Audit $audit, string $service): JsonResponse
    {
        if ($audit->report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        $reports = app(ServiceReportBuilder::class)->buildAll($audit);

        // A service with no report either never ran for this audit or
        // isn't a real service key — either way there is nothing to show,
        // and inventing an empty shell would misrepresent the audit.
        if (! isset($reports[$service])) {
            return ApiResponse::error('That service was not part of this audit.', 404);
        }

        return ApiResponse::success(
            app(ServiceReportVisibilityFilter::class)
                ->filter($reports[$service], $this->viewerEntitlements($request, $audit)),
        );
    }

    /**
     * Page explorer: every crawled page with cross-service issue counts
     * and screenshots. Full access only — per-page findings and
     * screenshots are the paid detail (see ServiceReportVisibilityFilter).
     */
    public function pages(Request $request, Audit $audit): JsonResponse
    {
        if ($audit->report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        if (! app(ReportAccessResolver::class)->hasFullAccess($audit, $request->user('sanctum'))) {
            return ApiResponse::error("Sign in as this audit's owner to explore page-level findings.", 403);
        }

        return ApiResponse::success(app(PageExplorerBuilder::class)->build($audit));
    }

    /**
     * One service's report as its own PDF (§35), rendered from the same
     * normalized model as the web report. Same access rules as the
     * combined PDF: full access plus the plan's pdf_export feature.
     */
    public function serviceReportPdf(Request $request, Audit $audit, string $service): JsonResponse|Response
    {
        if ($audit->report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        $viewer = $request->user('sanctum');

        if (! app(ReportAccessResolver::class)->hasFullAccess($audit, $viewer)) {
            return ApiResponse::error("Sign in as this audit's owner to export a PDF.", 403);
        }

        if (! $this->entitlements->forOrDeny($viewer)->allows(PlanFeature::PdfExport)) {
            return ApiResponse::error('PDF export is not included in your current plan.', 403);
        }

        // The owner's own plan decides how much of the report the export
        // contains, exactly as it does on the web (§48).
        $rendered = app(ServiceReportPdfService::class)->render(
            $audit,
            $service,
            $this->viewerEntitlements($request, $audit),
        );

        if ($rendered === null) {
            return ApiResponse::error('That service was not part of this audit.', 404);
        }

        return response($rendered['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$rendered['filename'].'"',
        ]);
    }

    public function reportPdf(Request $request, Audit $audit): JsonResponse|Response
    {
        $report = $audit->report;

        if ($report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        $viewer = $request->user('sanctum');
        $hasFullAccess = app(ReportAccessResolver::class)->hasFullAccess($audit, $viewer);

        if (! $hasFullAccess) {
            return ApiResponse::error("Sign in as this audit's owner to export a PDF.", 403);
        }

        if (! $this->entitlements->forOrDeny($viewer)->allows(PlanFeature::PdfExport)) {
            return ApiResponse::error('PDF export is not included in your current plan.', 403);
        }

        $pdf = app(ReportPdfService::class)->render($audit, $report, $this->viewerEntitlements($request, $audit));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="audit-report-'.$audit->id.'.pdf"',
        ]);
    }
}
