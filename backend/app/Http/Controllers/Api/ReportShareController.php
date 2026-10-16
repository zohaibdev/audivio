<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportShareRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Audit;
use App\Models\ReportShare;
use App\Services\Plan\EntitlementResolver;
use App\Services\Share\ReportShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's controls for sharing a report (§50).
 *
 * Two gates on every route here, in this order:
 *
 * 1. **Ownership.** The audit is resolved from the caller's own audits,
 *    never from a bare id — the id in the URL selects, it never
 *    authorizes (§37). A share is a grant of access to someone else's
 *    eyes; creating one on an audit you do not own would be the whole
 *    vulnerability in one request.
 * 2. **Plan.** Sharing is a plan feature, read through
 *    EntitlementResolver so a lapsed subscription loses it (§40).
 *
 * Nothing here ever returns an existing token. It is shown once, in the
 * response that created or regenerated it.
 */
class ReportShareController extends Controller
{
    public function __construct(
        private readonly ReportShareService $shares,
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function index(Request $request, int $auditId): JsonResponse
    {
        $audit = $this->ownedAudit($request, $auditId);

        if ($audit === null) {
            return $this->notFound();
        }

        return ApiResponse::success([
            'is_available_on_plan' => $this->canShare($request),
            'shares' => $audit->shares()
                ->orderByDesc('id')
                ->get()
                ->map(fn (ReportShare $share) => $this->shares->statusFor($share))
                ->all(),
        ]);
    }

    public function store(ReportShareRequest $request, int $auditId): JsonResponse
    {
        $audit = $this->ownedAudit($request, $auditId);

        if ($audit === null) {
            return $this->notFound();
        }

        if (! $this->canShare($request)) {
            return ApiResponse::error('Report sharing is not included in your current plan.', 403);
        }

        // A report that does not exist yet cannot be shared: the
        // recipient would open a link to nothing, and the owner would
        // have no way to tell why.
        if ($audit->report === null) {
            return ApiResponse::error('This audit has no report to share yet.', 422);
        }

        $created = $this->shares->create(
            $audit,
            $request->user(),
            $request->validated('password'),
            $request->safe()->only(['allow_pdf', 'allow_screenshots', 'allow_recommendations']),
            $request->validated('expires_at') ? new \DateTimeImmutable($request->validated('expires_at')) : null,
            $request,
        );

        return ApiResponse::success([
            // The one and only time this is ever returned.
            'url' => $created['url'],
            'share' => $this->shares->statusFor($created['share']),
        ], message: 'Share link created. Copy it now — it is not shown again.', status: 201);
    }

    public function update(ReportShareRequest $request, int $auditId, int $shareId): JsonResponse
    {
        $share = $this->ownedShare($request, $auditId, $shareId);

        if ($share === null) {
            return $this->notFound();
        }

        $permissions = $request->safe()->only(['allow_pdf', 'allow_screenshots', 'allow_recommendations']);

        if ($permissions !== []) {
            $share = $this->shares->updatePermissions($share, $permissions, $request);
        }

        if ($request->filled('password')) {
            $share = $this->shares->changePassword($share, $request->validated('password'), $request);
        }

        return ApiResponse::success(['share' => $this->shares->statusFor($share)], message: 'Share updated.');
    }

    public function destroy(Request $request, int $auditId, int $shareId): JsonResponse
    {
        $share = $this->ownedShare($request, $auditId, $shareId);

        if ($share === null) {
            return $this->notFound();
        }

        return ApiResponse::success(
            ['share' => $this->shares->statusFor($this->shares->revoke($share, $request))],
            message: 'Share link revoked.',
        );
    }

    public function regenerate(Request $request, int $auditId, int $shareId): JsonResponse
    {
        $share = $this->ownedShare($request, $auditId, $shareId);

        if ($share === null) {
            return $this->notFound();
        }

        if (! $this->canShare($request)) {
            return ApiResponse::error('Report sharing is not included in your current plan.', 403);
        }

        $result = $this->shares->regenerate($share, $request);

        return ApiResponse::success([
            'url' => $result['url'],
            'share' => $this->shares->statusFor($result['share']),
        ], message: 'New link created. The previous URL no longer works.');
    }

    /**
     * The share's own history — who opened it, and who failed to.
     */
    public function events(Request $request, int $auditId, int $shareId): JsonResponse
    {
        $share = $this->ownedShare($request, $auditId, $shareId);

        if ($share === null) {
            return $this->notFound();
        }

        return ApiResponse::success([
            'events' => $share->events()
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn ($event) => [
                    'event' => $event->event->value,
                    'label' => $event->event->label(),
                    'is_recipient_action' => $event->event->isRecipientAction(),
                    'ip_address' => $event->ip_address,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Scoped to the caller's own audits. Returns null rather than
     * throwing so every failure — wrong owner, no such audit — produces
     * one indistinguishable 404.
     */
    private function ownedAudit(Request $request, int $auditId): ?Audit
    {
        return $request->user()?->audits()->find($auditId);
    }

    private function ownedShare(Request $request, int $auditId, int $shareId): ?ReportShare
    {
        return $this->ownedAudit($request, $auditId)?->shares()->find($shareId);
    }

    private function canShare(Request $request): bool
    {
        return (bool) $this->entitlements->for($request->user())?->allows(PlanFeature::ReportSharing);
    }

    /**
     * Deliberately 404 and deliberately vague: distinguishing "no such
     * audit" from "not yours" confirms the existence of someone else's
     * audit to anyone who can count.
     */
    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Audit not found.', 404);
    }
}
