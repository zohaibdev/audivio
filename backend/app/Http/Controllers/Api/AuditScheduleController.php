<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScheduleFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditScheduleRequest;
use App\Http\Requests\UpdateAuditScheduleRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditSchedule;
use App\Models\Website;
use App\Services\Schedule\AuditSchedulerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditScheduleController extends Controller
{
    public function __construct(
        private readonly AuditSchedulerService $scheduler,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()->auditSchedules()->with('website')->orderByDesc('id')->get(),
        );
    }

    public function store(StoreAuditScheduleRequest $request): JsonResponse
    {
        $user = $request->user();

        // Scoped to the caller's own websites rather than looked up by
        // bare id and checked afterwards (§37). Same outcome for a valid
        // request, but an id belonging to someone else now reads as "no
        // such website of yours" instead of confirming the row exists.
        $website = $user->websites()->find($request->validated('website_id'));

        if ($website === null) {
            return ApiResponse::error('You can only schedule audits for your own websites.', 422);
        }

        if ($website->auditSchedules()->where('user_id', $user->id)->exists()) {
            return ApiResponse::error('This website already has an active schedule.', 422);
        }

        if (! $this->scheduler->canCreateSchedule($user)) {
            return ApiResponse::error('Scheduled audits are not included in your current plan, or you have reached your plan\'s recurring-website limit.', 403);
        }

        $frequency = ScheduleFrequency::from($request->validated('frequency'));

        // A separate check from canCreateSchedule() with its own message:
        // "your plan has no scheduled audits" and "your plan allows weekly
        // at most" are different problems needing different actions (§40).
        if (! $this->scheduler->allowsFrequency($user, $frequency)) {
            $max = $this->scheduler->maxFrequencyFor($user);

            return ApiResponse::error(
                "Your plan allows {$max->label()} audits at most, so it cannot run this website {$frequency->label()}.",
                403,
                ['limit' => 'max_audit_frequency'],
            );
        }

        $schedule = $this->scheduler->create($user, $website, $frequency);

        return ApiResponse::success($schedule, status: 201);
    }

    public function update(UpdateAuditScheduleRequest $request, AuditSchedule $auditSchedule): JsonResponse
    {
        if ($auditSchedule->user_id !== $request->user()->id) {
            return ApiResponse::error('Schedule not found.', 404);
        }

        $auditSchedule->update($request->validated());

        return ApiResponse::success($auditSchedule->fresh());
    }

    public function destroy(Request $request, AuditSchedule $auditSchedule): JsonResponse
    {
        if ($auditSchedule->user_id !== $request->user()->id) {
            return ApiResponse::error('Schedule not found.', 404);
        }

        $auditSchedule->delete();

        return ApiResponse::success(message: 'Schedule removed.');
    }
}
