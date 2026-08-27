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
use App\Services\Report\ReportVisibilityFilter;
use App\Services\User\UsageLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        private readonly UrlSecurityValidator $urlValidator,
        private readonly UsageLimitService $usageLimits,
    ) {}

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

    public function show(Audit $audit): JsonResponse
    {
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
}
