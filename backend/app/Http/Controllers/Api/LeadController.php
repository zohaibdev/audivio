<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeadRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;

/**
 * Public endpoint: the "Talk to a Consultant" / "Request an Audit
 * Review" CTAs from the spec's lead_generation section. No auth;
 * rate-limited at the route (`throttle:10,1`) to prevent spam.
 */
class LeadController extends Controller
{
    public function store(CreateLeadRequest $request): JsonResponse
    {
        $lead = Lead::create($request->validated());

        return ApiResponse::success(['lead_id' => $lead->id], message: 'Thanks — we\'ll be in touch.', status: 201);
    }
}
