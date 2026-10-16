<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBrandingRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BrandingProfile;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The caller's own white-label branding (§48).
 *
 * Readable and writable whatever plan they are on; only *applied* when
 * the plan allows it. The response says which, so the UI can show the
 * saved values alongside an honest "this is not active on your plan"
 * rather than hiding the form and losing the data behind it.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload($request));
    }

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = BrandingProfile::firstOrNew(['user_id' => $user->id]);

        $profile->fill($request->safe()->only([
            'brand_name',
            'contact_email',
            'contact_phone',
            'contact_website',
            'footer_text',
        ]));

        if ($request->hasFile('logo')) {
            $previous = $profile->logo_path;

            // Stored on the private disk with a generated name: the
            // uploaded filename is attacker-controlled and has no business
            // reaching the filesystem.
            $profile->logo_path = $request->file('logo')->store(
                "branding/{$user->id}",
                config('branding.logo_disk'),
            );

            // Removed only after the replacement is safely stored, so a
            // failed upload never leaves the user with no logo at all.
            if ($previous !== null && $previous !== $profile->logo_path) {
                Storage::disk(config('branding.logo_disk'))->delete($previous);
            }
        }

        $profile->user_id = $user->id;
        $profile->save();

        return ApiResponse::success($this->payload($request), message: 'Branding saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $user = $request->user();
        $profile = $user->brandingProfile;
        $entitlements = $this->entitlements->for($user);

        return [
            'brand_name' => $profile?->brand_name,
            'contact_email' => $profile?->contact_email,
            'contact_phone' => $profile?->contact_phone,
            'contact_website' => $profile?->contact_website,
            'footer_text' => $profile?->footer_text,
            'has_logo' => filled($profile?->logo_path),
            // Whether this branding is actually being applied. Saved but
            // inactive is a real, explainable state — not a reason to
            // pretend the settings do not exist.
            'is_active' => (bool) $entitlements?->allows(PlanFeature::WhiteLabel) && (bool) $profile?->hasContent(),
            'is_available_on_plan' => (bool) $entitlements?->allows(PlanFeature::WhiteLabel),
        ];
    }
}
