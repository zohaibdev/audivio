<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderRequest extends FormRequest
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
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_tokens' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'temperature' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2'],
            'request_timeout' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'daily_request_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'monthly_request_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'mobile_enabled' => ['sometimes', 'boolean'],
            'desktop_enabled' => ['sometimes', 'boolean'],
            'cost_per_1k_input_tokens' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_per_1k_output_tokens' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
