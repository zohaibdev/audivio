<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLeadRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Lead::with(['audit', 'website'])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // Every lead for one site, which is the view a salesperson working
        // an account actually wants (§55). Only possible now that a lead
        // carries a real website_id rather than a typed-in URL.
        if ($request->filled('website_id')) {
            $query->where('website_id', $request->integer('website_id'));
        }

        return ApiResponse::success($query->paginate(25));
    }

    public function show(Lead $lead): JsonResponse
    {
        return ApiResponse::success($lead->load('audit', 'website', 'activities.user'));
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $previousStatus = $lead->status->value;
        $lead->update(['status' => $request->validated('status')]);

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'note' => "Status changed from {$previousStatus} to {$lead->status->value}.",
        ]);

        return ApiResponse::success($lead->fresh());
    }

    public function storeActivity(Request $request, Lead $lead): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:5000']]);

        $activity = LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'note' => $request->string('note'),
        ]);

        return ApiResponse::success($activity, status: 201);
    }
}
