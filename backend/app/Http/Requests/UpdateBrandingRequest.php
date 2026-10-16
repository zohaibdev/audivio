<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * White-label branding input (§48).
 *
 * Accepted regardless of whether the caller's plan currently allows
 * white-labelling — the entitlement is checked at render time, not at
 * save time, so a user who downgrades keeps their details and gets them
 * back on upgrading rather than having to re-enter everything.
 */
class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact_website' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'logo' => [
                'sometimes',
                'nullable',
                'file',
                // Validated by actual MIME type, not by the filename: an
                // uploaded file's extension is attacker-controlled.
                Rule::file()
                    ->types(['png', 'jpg', 'jpeg', 'webp'])
                    ->max((int) (config('branding.max_logo_bytes') / 1024)),
            ],
        ];
    }
}
