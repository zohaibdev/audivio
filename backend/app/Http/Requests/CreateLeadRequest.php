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
            'source' => ['required', Rule::in(['talk_to_consultant', 'request_audit_review'])],
        ];
    }
}
