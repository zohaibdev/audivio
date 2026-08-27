<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAuditRequest;
use App\Http\Responses\ApiResponse;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\Website;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlSecurityValidator;
use App\Services\Report\ReportAccessResolver;
use App\Services\Report\ReportPdfService;
use App\Services\Report\ReportVisibilityFilter;
use App\Services\User\UsageLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuditController extends Controller
{
    public function __construct(
        private readonly UrlSecurityValidator $urlValidator,
        private readonly UsageLimitService $usageLimits,
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
        $url = $request->string('url')->toString();
        $user = $request->user('sanctum');

        try {
            $this->urlValidator->assertSafe($url);
        } catch (SsrfValidationException $e) {
            return ApiResponse::error("This URL cannot be audited: {$e->getMessage()}", 422);
        }

        if (! $this->usageLimits->canCreateAudit($user, $request->ip())) {
            return ApiResponse::error(
                $user
                    ? 'You have reached your daily audit limit for your current plan.'
                    : 'You have already used your free audit. Sign up to run more.',
                429,
            );
        }

        $website = Website::create([
            'user_id' => $user?->id,
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
        ]);

        $audit = Audit::create([
            'website_id' => $website->id,
            'user_id' => $user?->id,
            'status' => AuditStatus::Pending,
            'max_pages' => $this->usageLimits->maxPagesFor($user),
        ]);

        $this->usageLimits->recordAuditCreated($user, $request->ip(), $website, $audit);

        CrawlWebsiteJob::dispatch($audit);

        // Report the status as of creation (Pending), not $audit->status:
        // with a synchronous queue driver (as in tests), dispatch() above
        // runs the whole job inline and updates the DB row before this
        // line runs, but this PHP object's in-memory status was never
        // refreshed — reading it back would report a queue-driver-
        // dependent value instead of the one true fact at response time:
        // the audit was just accepted and queued.
        return ApiResponse::success(['audit_id' => $audit->id, 'status' => AuditStatus::Pending->value], status: 202);
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
            'pages_crawled' => $audit->pages_crawled,
            'max_pages' => $audit->max_pages,
            'error_message' => $audit->error_message,
        ]);
    }

    public function report(Request $request, Audit $audit): JsonResponse
    {
        $report = $audit->report;

        if ($report === null) {
            return ApiResponse::error('Report is not ready yet.', 404);
        }

        $hasFullAccess = app(ReportAccessResolver::class)->hasFullAccess($audit, $request->user('sanctum'));
        $content = app(ReportVisibilityFilter::class)->filter($report->content, $hasFullAccess);

        return ApiResponse::success($content);
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

        if (! $viewer->plan()?->hasFeature('pdf_export')) {
            return ApiResponse::error('PDF export is not included in your current plan.', 403);
        }

        $pdf = app(ReportPdfService::class)->render($audit, $report);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="audit-report-'.$audit->id.'.pdf"',
        ]);
    }
}
