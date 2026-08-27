<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditSchedule;
use Illuminate\Http\JsonResponse;

class AdminAuditScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            AuditSchedule::with('user', 'website')->orderByDesc('id')->paginate(25),
        );
    }
}
