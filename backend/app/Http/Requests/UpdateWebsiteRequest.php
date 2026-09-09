<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteRequest extends FormRequest
{
    /**
     * Ownership is checked in the controller, against the caller's own
     * websites, rather than here — this request never sees which website
     * is being addressed, only the fields being written to it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the profile fields a user fills in themselves. `domain`,
     * `normalized_domain`, `primary_url`, `user_id` and `platform` are
     * absent by design: they are derived from what was audited, and a
     * writable `normalized_domain` would let one record claim another's
     * identity.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'products_or_services' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // What the business wants to be found for in AI search —
            // the context the AIO module's goal-alignment prompts are
            // measured against (section 42).
            'aio_goal' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // ISO 3166-1 alpha-2. Stored as two characters so it can be
            // joined against a country list later without re-parsing free
            // text people typed differently every time.
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'budget' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'average_product_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'competitors' => ['sometimes', 'nullable', 'array', 'max:20'],
            'competitors.*' => ['string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }
}
