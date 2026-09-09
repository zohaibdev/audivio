<?php

namespace App\Services\Report;

use App\Enums\PlanFeature;
use App\Models\Audit;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Which branding a rendered report carries (§48).
 *
 * Three separate questions, deliberately answered in this order:
 *
 * 1. Does this audit have an owner? An anonymous audit has no agency
 *    behind it.
 * 2. Does that owner's **current** plan include white-labelling? Asked at
 *    render time through `EntitlementResolver`, never cached — a plan
 *    that lapsed yesterday must not still be re-branding reports today.
 * 3. Has the owner actually filled anything in? An empty profile is the
 *    same as none: applying it would replace our branding with nothing.
 *
 * A "no" at any step falls back to the product's own branding, which is
 * always a complete, valid answer — there is no state in which a report
 * renders with no identity on it at all.
 */
class BrandingResolver
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    /**
     * @return array<string, mixed>
     */
    public function forAudit(Audit $audit): array
    {
        $owner = $audit->user;

        if ($owner === null) {
            return $this->default();
        }

        $entitlements = $this->entitlements->for($owner);

        if ($entitlements === null || ! $entitlements->allows(PlanFeature::WhiteLabel)) {
            return $this->default();
        }

        $profile = $owner->brandingProfile;

        if ($profile === null || ! $profile->hasContent()) {
            return $this->default();
        }

        return [
            'is_white_label' => true,
            // Falls back to our name rather than rendering a nameless
            // report: a partly-filled profile should still produce a
            // document that says who issued it.
            'brand_name' => $profile->brand_name ?: config('app.name'),
            'logo_data_uri' => $this->logoDataUri($profile->logo_path),
            'contact_email' => $profile->contact_email,
            'contact_phone' => $profile->contact_phone,
            'contact_website' => $profile->contact_website,
            'footer_text' => $profile->footer_text ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function default(): array
    {
        return [
            'is_white_label' => false,
            'brand_name' => config('app.name'),
            'logo_data_uri' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'contact_website' => null,
            'footer_text' => null,
        ];
    }

    /**
     * The logo inlined as a data URI.
     *
     * dompdf cannot fetch remote assets, and the logo lives on a private
     * disk behind a signed route it has no session for — so a URL would
     * render as a broken image in the one place the branding matters most.
     * Inlining is the only approach that actually works here.
     *
     * A logo that cannot be read is dropped rather than failing the
     * export: a missing image is a cosmetic problem, a failed download is
     * not.
     */
    private function logoDataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(config('branding.logo_disk'));

        if (! $disk->exists($path)) {
            return null;
        }

        try {
            $contents = $disk->get($path);
        } catch (\Throwable) {
            return null;
        }

        if ($contents === null || strlen($contents) > (int) config('branding.max_logo_bytes')) {
            return null;
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
