<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'admin' middleware at the route level
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'features' => ['required', 'array'],
            'features.*.key' => ['required', 'string', 'exists:features,key'],
            'features.*.is_enabled' => ['required', 'boolean'],
        ];
    }
}
