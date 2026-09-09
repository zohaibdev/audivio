<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'audit_id' => ['sometimes', 'nullable', 'integer', 'exists:audits,id'],

            // Accepted, then filtered server-side against the audit and
            // the service catalogue (§55). Validating the shape here keeps
            // junk out; validating the *content* is the service's job,
            // because only it knows which audit these are claimed against.
            'issue_codes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'issue_codes.*' => ['string', 'max:191'],
            'requested_services' => ['sometimes', 'nullable', 'array', 'max:10'],
            'requested_services.*' => ['string', 'max:50'],

            // No website_id: a public endpoint that accepted one would let
            // anyone file leads against someone else's site.
            'source' => ['required', Rule::in(config('leads.sources'))],
        ];
    }
}
