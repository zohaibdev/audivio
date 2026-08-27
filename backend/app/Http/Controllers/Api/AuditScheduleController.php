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
        $website = Website::findOrFail($request->validated('website_id'));

        if ($website->user_id !== $user->id) {
            return ApiResponse::error('You can only schedule audits for your own websites.', 422);
        }

        if ($website->auditSchedules()->where('user_id', $user->id)->exists()) {
            return ApiResponse::error('This website already has an active schedule.', 422);
        }

        if (! $this->scheduler->canCreateSchedule($user)) {
            return ApiResponse::error('Scheduled audits are not included in your current plan, or you have reached your plan\'s recurring-website limit.', 403);
        }

        $schedule = $this->scheduler->create($user, $website, ScheduleFrequency::from($request->validated('frequency')));

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
